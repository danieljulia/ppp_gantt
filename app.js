;(() => {
  dayjs.extend(window.dayjs_plugin_customParseFormat)

  const DAY_PX = 24 // keep in sync with CSS var --day-px
  const EDIT_MIN_PX = 240 // min width used previously; now using auto width in edit
  const SUBTASK_GAP_PX = 6

  // Contrast-aware text color selection (WCAG-inspired)
  function hexToRgb(hex) {
    const m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex || '')
    if (!m) return { r: 153, g: 153, b: 153 }
    return { r: parseInt(m[1], 16), g: parseInt(m[2], 16), b: parseInt(m[3], 16) }
  }
  function relLuminance({ r, g, b }) {
    const srgb = [r, g, b].map(v => v / 255)
    const lin = srgb.map(c => c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4))
    return 0.2126 * lin[0] + 0.7152 * lin[1] + 0.0722 * lin[2]
  }
  function contrastRatio(l1, l2) {
    const [L1, L2] = l1 > l2 ? [l1, l2] : [l2, l1]
    return (L1 + 0.05) / (L2 + 0.05)
  }
  function bestTextColor(bgHex) {
    const bgL = relLuminance(hexToRgb(bgHex || '#999999'))
    const white = relLuminance({ r: 255, g: 255, b: 255 })
    const black = relLuminance({ r: 0, g: 0, b: 0 })
    const cWhite = contrastRatio(white, bgL)
    const cBlack = contrastRatio(black, bgL)
    return cWhite >= cBlack ? '#ffffff' : '#000000'
  }

  const api = {
    async getProjects() {
      const res = await fetch('api.php?r=list_projects')
      return res.json()
    },
    async createProject(payload) {
      const res = await fetch('api.php?r=create_project', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
      })
      return res.json()
    },
    async getProject(id) {
      const res = await fetch('api.php?r=get_project&id=' + encodeURIComponent(id))
      return res.json()
    },
    async updateProject(payload) {
      const res = await fetch('api.php?r=update_project', {
        method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
      })
      return res.json()
    },
    async addUser(payload) {
      const res = await fetch('api.php?r=add_user', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
      return res.json()
    },
    async updateUser(payload) {
      const res = await fetch('api.php?r=update_user', { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
      return res.json()
    },
    async deleteUser(payload) {
      const res = await fetch('api.php?r=delete_user', { method: 'DELETE', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
      return res.json()
    },
    async addMainTask(payload) {
      const res = await fetch('api.php?r=add_main_task', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
      return res.json()
    },
    async updateMainTask(payload) {
      const res = await fetch('api.php?r=update_main_task', { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
      return res.json()
    },
    async deleteMainTask(payload) {
      const res = await fetch('api.php?r=delete_main_task', { method: 'DELETE', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
      return res.json()
    },
    async addSubtask(payload) {
      const res = await fetch('api.php?r=add_subtask', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
      return res.json()
    },
    async updateSubtask(payload) {
      const res = await fetch('api.php?r=update_subtask', { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
      return res.json()
    },
    async deleteSubtask(payload) {
      const res = await fetch('api.php?r=delete_subtask', { method: 'DELETE', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
      return res.json()
    },
  }

  const App = {
    setup() {
      const state = Vue.reactive({
        projects: [],
        projectId: null,
        project: null,
        users: [],
        tasks: [],
        loading: true,
        error: null,
        daysHorizon: 60,
        months: [],
        weekStartDays: [],
        actualEndDay: 0,
        actualEndDayPx: 0,
        endDate: null,
        endDateFormatted: null,
        selectedSubtaskId: null,
        editingUserId: null,
        isEditing: false,
      })

      function computeRowWidth(task) {
        const totalDays = task.subtasks.reduce((acc, s) => acc + Number(s.duration_days || 0), 0)
        const gaps = Math.max(0, task.subtasks.length - 1) * SUBTASK_GAP_PX
        return totalDays * DAY_PX + gaps
      }

      function computeSubtaskLeft(task, subIndex) {
        const startOffset = Number(task.start_offset_days || 0)
        const daysBefore = task.subtasks.slice(0, subIndex).reduce((a, s) => a + Number(s.duration_days || 0), 0)
        return (startOffset + daysBefore) * DAY_PX + subIndex * SUBTASK_GAP_PX
      }
      
      function computeAddButtonLeft(task) {
        if (!task.subtasks || task.subtasks.length === 0) {
          const startOffset = Number(task.start_offset_days || 0)
          return startOffset * DAY_PX + 8
        }
        const lastSubtaskIndex = task.subtasks.length - 1
        const lastSubtaskLeft = computeSubtaskLeft(task, lastSubtaskIndex)
        const lastSubtaskWidth = Number(task.subtasks[lastSubtaskIndex].duration_days || 0) * DAY_PX
        return lastSubtaskLeft + lastSubtaskWidth + 8 // 8px gap after last subtask
      }

      function colorForUser(userId) {
        const u = state.users.find(u => u.id === userId)
        // lighter default gray when unassigned
        return u ? u.color : '#dcdcdc'
      }

      function lightenColor(hex, ratio = 0.15) {
        if (!hex) return '#eeeeee'
        const m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex)
        if (!m) return hex
        const r = Math.round(parseInt(m[1], 16) + (255 - parseInt(m[1], 16)) * ratio)
        const g = Math.round(parseInt(m[2], 16) + (255 - parseInt(m[2], 16)) * ratio)
        const b = Math.round(parseInt(m[3], 16) + (255 - parseInt(m[3], 16)) * ratio)
        const toHex = (v) => v.toString(16).padStart(2, '0')
        return `#${toHex(r)}${toHex(g)}${toHex(b)}`
      }

      function getUserInitial(userId) {
        const u = state.users.find(u => u.id === userId)
        if (!u || !u.name) return ''
        return u.name.charAt(0).toUpperCase()
      }

      function computeBarWidthValue(sub) {
        if (state.selectedSubtaskId === sub.id) return 'auto'
        return (Number(sub.duration_days || 0) * DAY_PX) + 'px'
      }

      async function loadProjects() {
        state.loading = true
        const res = await api.getProjects()
        state.projects = res.projects
        if (state.projects.length === 0) {
          const created = await api.createProject({ name: 'untitled', start_date: dayjs().format('YYYY-MM-DD') })
          state.projectId = created.id
        } else {
          state.projectId = state.projects[0].id
        }
        await loadProject()
        state.loading = false
      }

      async function loadProject() {
        if (!state.projectId) return
        const res = await api.getProject(state.projectId)
        state.project = res.project
        state.users = res.users
        state.tasks = res.main_tasks
        recomputeHorizon()
      }

      function recomputeHorizon() {
        let maxDays = 30
        let actualEndDay = 0
        
        for (const t of state.tasks) {
          const startOffset = Number(t.start_offset_days || 0)
          const taskDays = t.subtasks.reduce((a, s) => a + Number(s.duration_days || 0), 0)
          const totalDays = startOffset + taskDays
          
          if (totalDays > maxDays) maxDays = totalDays
          
          // Calculate actual end day for this task
          // For each subtask, calculate its end position: startOffset + cumulative duration + this subtask's duration
          // Then find the maximum end position across all subtasks
          let maxEndDay = startOffset
          let cumulativeDays = 0
          for (const s of t.subtasks) {
            const subStartDay = startOffset + cumulativeDays
            const subEndDay = subStartDay + Number(s.duration_days || 0)
            if (subEndDay > maxEndDay) {
              maxEndDay = subEndDay
            }
            cumulativeDays += Number(s.duration_days || 0)
          }
          
          if (maxEndDay > actualEndDay) {
            actualEndDay = maxEndDay
          }
        }
        
        state.daysHorizon = Math.max(maxDays, 30)
        state.actualEndDay = actualEndDay
        computeMonths()
      }

      function computeMonths() {
        if (!state.project) return
        const start = dayjs(state.project.start_date, 'YYYY-MM-DD')
        const months = []
        const monthMap = new Map()
        const weekStartDays = []
        
        // Track which days we've covered and group by month
        for (let i = 0; i < state.daysHorizon; i++) {
          const d = start.add(i, 'day')
          const monthKey = d.format('YYYY-MM')
          const monthName = d.format('MMMM')
          
          if (!monthMap.has(monthKey)) {
            monthMap.set(monthKey, { name: monthName, weekStarts: new Set() })
          }
          
          // Check if this is the start of a week (Monday, or first day of range)
          const isMonday = d.day() === 1
          const isFirstDay = i === 0
          
          if (isMonday || isFirstDay) {
            const dayOfMonth = d.date()
            monthMap.get(monthKey).weekStarts.add(dayOfMonth)
            weekStartDays.push(i)
          }
        }
        
        // Convert to array format, sorted by date
        const sortedMonths = Array.from(monthMap.entries()).sort((a, b) => a[0].localeCompare(b[0]))
        state.months = sortedMonths.map(([key, data]) => ({
          name: data.name,
          weeks: Array.from(data.weekStarts).sort((a, b) => a - b).map(day => ({ day }))
        }))
        
        // Store week start days for vertical lines
        state.weekStartDays = weekStartDays
        
        // Compute actual end date based on last subtask
        if (state.actualEndDay > 0) {
          const endDate = start.add(state.actualEndDay - 1, 'day')
          state.endDate = endDate.format('YYYY-MM-DD')
          state.endDateFormatted = endDate.format('MMM D, YYYY')
          state.actualEndDayPx = state.actualEndDay * DAY_PX
        } else {
          state.endDate = null
          state.endDateFormatted = null
          state.actualEndDayPx = 0
        }
      }

      async function onProjectNameChange(e) {
        if (!state.isEditing) return
        const name = e.target.innerText.trim() || 'untitled'
        await api.updateProject({ id: state.project.id, name })
        state.project.name = name
      }

      async function onProjectDateChange(e) {
        if (!state.isEditing) return
        const start = e.target.value
        await api.updateProject({ id: state.project.id, start_date: start })
        state.project.start_date = start
        computeMonths()
      }

      async function addMainTask() {
        if (!state.isEditing) return
        const res = await api.addMainTask({ project_id: state.project.id, name: 'Main task' })
        await loadProject()
      }

      async function renameMainTask(task, e) {
        if (!state.isEditing) return
        await api.updateMainTask({ id: task.id, name: e.target.value })
        task.name = e.target.value
      }

      async function deleteMainTask(task) {
        if (!state.isEditing) return
        if (!confirm('Delete main task and all its subtasks?')) return
        await api.deleteMainTask({ id: task.id })
        await loadProject()
      }

      async function addSubtask(task) {
        if (!state.isEditing) return
        await api.addSubtask({ main_task_id: task.id, duration_days: 7 })
        await loadProject()
      }

      async function updateSubtaskField(sub, field, value) {
        if (!state.isEditing) return
        const payload = { id: sub.id }
        payload[field] = value
        await api.updateSubtask(payload)
        sub[field] = value
        recomputeHorizon()
      }

      async function deleteSubtask(sub) {
        if (!state.isEditing) return
        if (!confirm('Delete subtask?')) return
        await api.deleteSubtask({ id: sub.id })
        await loadProject()
      }

      // Users
      async function addUser() {
        if (!state.isEditing) return
        const name = prompt('User name?')
        if (!name) return
        // Figma-inspired color palette (pastel, light, vivid colors)
        // Based on: #fbe3be, #c3befb, #fbbef7
        const palette = [
          '#fbe3be', '#c3befb', '#fbbef7', // Original Figma colors
          '#befe7a', '#ffbe7a', '#7abefe', '#fe7a7a', '#7afe7a', // Similar vibrant pastels
          '#be7afe', '#febe3b', '#3bfebe', '#bebefe', '#febebe', '#befebe', // More variations
          '#d9beff', '#ffe0be', '#beffe0', '#ffbed9', '#bed9ff', '#ffd9be', // Additional soft colors
          '#e8c8ff', '#ffe8c8', '#c8ffe8', '#ffe8d9', '#c8e8ff', '#fff0c8' // Extra light variants
        ]
        const color = palette[(state.users.length) % palette.length]
        await api.addUser({ project_id: state.project.id, name, color })
        await loadProject()
      }

      async function updateUser(user, field, value) {
        if (!state.isEditing) return
        await api.updateUser({ id: user.id, [field]: value })
        user[field] = value
      }
      
      function startEditingUser(userId) {
        if (!state.isEditing) return
        // Only start editing if not already editing this user
        if (state.editingUserId !== userId) {
          state.editingUserId = userId
        }
      }
      
      function stopEditingUser() {
        state.editingUserId = null
      }

      async function deleteUser(user) {
        if (!confirm('Delete user? Subtasks assigned will be unassigned.')) return
        await api.deleteUser({ id: user.id })
        await loadProject()
      }

      function selectSubtask(subtaskId) {
        state.selectedSubtaskId = subtaskId
      }
      function deselectSubtask() {
        state.selectedSubtaskId = null
      }

      // Row reordering handlers
      function onRowDragStart(e, task, taskIndex) {
        if (!state.isEditing) {
          e.preventDefault()
          return false
        }
        // Don't start drag if clicking on inputs/buttons
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'BUTTON' || e.target.closest('.row-actions')) {
          e.preventDefault()
          return false
        }
        draggingRow = task
        draggingRowType = 'main_task'
        draggingRowIndex = taskIndex
        e.dataTransfer.effectAllowed = 'move'
        e.dataTransfer.setData('text/html', '') // Required for some browsers
        e.currentTarget.classList.add('dragging')
      }
      
      function onRowDragEnd(e) {
        e.currentTarget.classList.remove('dragging')
        draggingRow = null
        draggingRowType = null
        draggingRowIndex = null
      }
      
      function onRowDragOver(e, targetTask, targetIndex) {
        if (!draggingRow || draggingRowType !== 'main_task') return
        e.preventDefault()
        e.dataTransfer.dropEffect = 'move'
        
        const rowElement = e.currentTarget.closest('.gantt-row')
        if (rowElement) {
          const rect = rowElement.getBoundingClientRect()
          const midpoint = rect.top + rect.height / 2
          if (e.clientY < midpoint) {
            rowElement.classList.add('drag-over-top')
            rowElement.classList.remove('drag-over-bottom')
          } else {
            rowElement.classList.add('drag-over-bottom')
            rowElement.classList.remove('drag-over-top')
          }
        }
      }
      
      function onRowDragLeave(e) {
        const rowElement = e.currentTarget.closest('.gantt-row')
        if (rowElement) {
          rowElement.classList.remove('drag-over-top', 'drag-over-bottom')
        }
      }
      
      async function onRowDrop(e, targetTask, targetIndex) {
        e.preventDefault()
        if (!draggingRow || draggingRowType !== 'main_task' || draggingRowIndex === null) return
        
        const rowElement = e.currentTarget.closest('.gantt-row')
        if (rowElement) {
          rowElement.classList.remove('drag-over-top', 'drag-over-bottom')
        }
        
        if (draggingRowIndex === targetIndex) {
          draggingRow = null
          draggingRowType = null
          draggingRowIndex = null
          return
        }
        
        // Calculate new position
        const rect = rowElement ? rowElement.getBoundingClientRect() : null
        const midpoint = rect ? rect.top + rect.height / 2 : null
        let newIndex = targetIndex
        if (draggingRowIndex < targetIndex && midpoint && e.clientY < midpoint) {
          newIndex = targetIndex - 1
        } else if (draggingRowIndex > targetIndex && midpoint && e.clientY >= midpoint) {
          newIndex = targetIndex + 1
        }
        
        // Reorder in array
        const tasks = [...state.tasks]
        const [movedTask] = tasks.splice(draggingRowIndex, 1)
        tasks.splice(newIndex, 0, movedTask)
        
        // Update positions in backend
        for (let i = 0; i < tasks.length; i++) {
          await api.updateMainTask({ id: tasks[i].id, position: i })
        }
        
        // Reload project to get updated order
        await loadProject()
        
        draggingRow = null
        draggingRowType = null
        draggingRowIndex = null
      }
      
      // Subtask reordering handlers
      let subtaskDragStartPos = null
      let subtaskDragStartTime = null
      function onSubtaskDragStart(e, task, subtask, subIndex) {
        if (!state.isEditing) {
          e.preventDefault()
          return false
        }
        // Don't start drag if clicking on inputs/buttons/resizer
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'BUTTON' || e.target.closest('.resizer')) {
          e.preventDefault()
          return false
        }
        
        // Store initial position and time to detect if this is a real drag vs click
        subtaskDragStartPos = { x: e.clientX, y: e.clientY }
        subtaskDragStartTime = Date.now()
        
        // Cancel any ongoing horizontal drag
        if (dragging) {
          dragging = null
          window.removeEventListener('mousemove', onMouseMove)
          window.removeEventListener('mouseup', onMouseUp)
        }
        draggingRow = subtask
        draggingRowType = 'subtask'
        draggingRowIndex = subIndex
        draggingRowMainTaskId = task.id
        e.dataTransfer.effectAllowed = 'move'
        e.dataTransfer.setData('text/html', '')
        e.currentTarget.classList.add('dragging')
        e.stopPropagation()
      }
      
      function onSubtaskDragEnd(e) {
        e.currentTarget.classList.remove('dragging')
        
        // If it was just a click (no significant drag), select the subtask
        let wasClick = false
        if (subtaskDragStartPos && subtaskDragStartTime) {
          const dx = Math.abs(e.clientX - subtaskDragStartPos.x)
          const dy = Math.abs(e.clientY - subtaskDragStartPos.y)
          const timeElapsed = Date.now() - subtaskDragStartTime
          // If minimal movement and quick (click-like), treat as click
          if ((dx < 5 && dy < 5) || (timeElapsed < 200 && dy < 10)) {
            wasClick = true
            if (draggingRow) {
              selectSubtask(draggingRow.id)
            }
          }
          subtaskDragStartPos = null
          subtaskDragStartTime = null
        }
        
        if (!wasClick) {
          draggingRow = null
          draggingRowType = null
          draggingRowIndex = null
          draggingRowMainTaskId = null
        } else {
          // Still clear dragging state after a short delay to allow click handler
          setTimeout(() => {
            draggingRow = null
            draggingRowType = null
            draggingRowIndex = null
            draggingRowMainTaskId = null
          }, 50)
        }
      }
      
      function onSubtaskDragOver(e, task, targetSubtask, targetIndex) {
        if (!draggingRow || draggingRowType !== 'subtask' || draggingRowMainTaskId !== task.id) return
        e.preventDefault()
        e.stopPropagation()
        e.dataTransfer.dropEffect = 'move'
        
        const barElement = e.currentTarget
        if (barElement) {
          const rect = barElement.getBoundingClientRect()
          const midpoint = rect.left + rect.width / 2
          if (e.clientX < midpoint) {
            barElement.classList.add('drag-over-left')
            barElement.classList.remove('drag-over-right')
          } else {
            barElement.classList.add('drag-over-right')
            barElement.classList.remove('drag-over-left')
          }
        }
      }
      
      function onSubtaskDragLeave(e) {
        const barElement = e.currentTarget
        if (barElement) {
          barElement.classList.remove('drag-over-left', 'drag-over-right')
        }
      }
      
      async function onSubtaskDrop(e, task, targetSubtask, targetIndex) {
        e.preventDefault()
        e.stopPropagation()
        if (!draggingRow || draggingRowType !== 'subtask' || draggingRowIndex === null || draggingRowMainTaskId !== task.id) return
        
        const barElement = e.currentTarget
        if (barElement) {
          barElement.classList.remove('drag-over-left', 'drag-over-right')
        }
        
        if (draggingRowIndex === targetIndex) {
          draggingRow = null
          draggingRowType = null
          draggingRowIndex = null
          draggingRowMainTaskId = null
          return
        }
        
        // Calculate new position
        const rect = barElement ? barElement.getBoundingClientRect() : null
        const midpoint = rect ? rect.left + rect.width / 2 : null
        let newIndex = targetIndex
        if (draggingRowIndex < targetIndex && midpoint && e.clientX < midpoint) {
          newIndex = targetIndex - 1
        } else if (draggingRowIndex > targetIndex && midpoint && e.clientX >= midpoint) {
          newIndex = targetIndex + 1
        }
        
        // Reorder subtasks in array
        const subtasks = [...task.subtasks]
        const [movedSubtask] = subtasks.splice(draggingRowIndex, 1)
        subtasks.splice(newIndex, 0, movedSubtask)
        
        // Update positions in backend
        for (let i = 0; i < subtasks.length; i++) {
          await api.updateSubtask({ id: subtasks[i].id, position: i })
        }
        
        // Reload project to get updated order
        await loadProject()
        
        draggingRow = null
        draggingRowType = null
        draggingRowIndex = null
        draggingRowMainTaskId = null
      }

      // Drag-resize and drag-move (for timeline bars)
      let dragging = null
      let clickStartPos = null
      let clickedSubtaskId = null
      
      function onBarMouseDown(e, task, sub, subIndex) {
        if (!state.isEditing) return
        const isResizer = e.target.classList.contains('resizer')
        const isInput = e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'BUTTON'
        
        if (isInput) {
          // If clicking input, just select (don't drag)
          selectSubtask(sub.id)
          return
        }
        
        // Record click position to detect click vs drag
        clickStartPos = { x: e.clientX, y: e.clientY }
        clickedSubtaskId = sub.id
        
        const startX = e.clientX
        const startY = e.clientY
        const currentLeft = computeSubtaskLeft(task, subIndex)
        
        // Handler to check if this is a horizontal or vertical drag
        let hasMoved = false
        const checkDragDirection = (moveEvent) => {
          if (hasMoved) return
          const dx = Math.abs(moveEvent.clientX - startX)
          const dy = Math.abs(moveEvent.clientY - startY)
          
          // If vertical movement is significantly more than horizontal, let HTML5 drag handle it
          if (dy > dx + 5 && dy > 10) {
            hasMoved = true
            dragging = null
            window.removeEventListener('mousemove', checkDragDirection)
            window.removeEventListener('mousemove', onMouseMove)
            window.removeEventListener('mouseup', onMouseUp)
            return
          }
          
          // If horizontal movement is significant, proceed with horizontal drag
          if (dx > 5) {
            hasMoved = true
            e.preventDefault()
            window.removeEventListener('mousemove', checkDragDirection)
            
            if (isResizer) {
              // Resize mode
              const startDays = Number(sub.duration_days)
              dragging = { mode: 'resize', task, sub, startX, startDays }
            } else {
              // Move mode - move entire row
              const startOffset = Number(task.start_offset_days || 0)
              dragging = { mode: 'move', task, startX, startOffset, startLeft: currentLeft }
              // Select subtask when starting to move
              selectSubtask(sub.id)
            }
            
            window.addEventListener('mousemove', onMouseMove)
            window.addEventListener('mouseup', onMouseUp)
          }
        }
        
        // Start checking drag direction
        const cleanupCheckDrag = () => {
          window.removeEventListener('mousemove', checkDragDirection)
          window.removeEventListener('mouseup', handleMouseUp)
        }
        
        const handleMouseUp = (upEvent) => {
          cleanupCheckDrag()
          if (!hasMoved) {
            // It was just a click, not a drag - select the subtask immediately
            // Use a small timeout to ensure it fires after any drag events
            setTimeout(() => {
              selectSubtask(sub.id)
            }, 0)
            clickStartPos = null
            clickedSubtaskId = null
          }
        }
        
        window.addEventListener('mousemove', checkDragDirection)
        window.addEventListener('mouseup', handleMouseUp)
      }
      function onMouseMove(e) {
        if (!dragging) return
        const dx = e.clientX - dragging.startX
        const dDays = Math.round(dx / DAY_PX)
        
        if (dragging.mode === 'resize') {
          const newDays = Math.max(1, dragging.startDays + dDays)
          if (newDays !== dragging.sub.duration_days) {
            dragging.sub.duration_days = newDays
          }
        } else if (dragging.mode === 'move') {
          const newOffset = Math.max(0, dragging.startOffset + dDays)
          dragging.task.start_offset_days = newOffset
        }
      }
      async function onMouseUp(e) {
        if (dragging) {
          if (dragging.mode === 'resize') {
            const { sub } = dragging
            const newDays = Math.max(1, Number(sub.duration_days))
            await api.updateSubtask({ id: sub.id, duration_days: newDays })
          } else if (dragging.mode === 'move') {
            const { task } = dragging
            const newOffset = Math.max(0, Number(task.start_offset_days || 0))
            await api.updateMainTask({ id: task.id, start_offset_days: newOffset })
          }
          dragging = null
        } else if (clickStartPos && clickedSubtaskId) {
          // Check if it was a click (not a drag)
          const dx = Math.abs(e.clientX - clickStartPos.x)
          const dy = Math.abs(e.clientY - clickStartPos.y)
          if (dx < 5 && dy < 5) {
            // It was a click, select the subtask
            selectSubtask(clickedSubtaskId)
          }
          clickStartPos = null
          clickedSubtaskId = null
        }
        
        window.removeEventListener('mousemove', onMouseMove)
        window.removeEventListener('mouseup', onMouseUp)
        recomputeHorizon()
      }

      Vue.onMounted(() => {
        loadProjects()
        // Deselect subtask when clicking outside bars (but not on other inputs/buttons)
        document.addEventListener('click', (e) => {
          if (!e.target.closest('.bar') && !e.target.closest('.gantt')) {
            state.selectedSubtaskId = null
          }
          // Stop editing user when clicking outside user pills
          if (!e.target.closest('.user-pill')) {
            state.editingUserId = null
          }
        })
      })

      function toggleEditing() {
        state.isEditing = !state.isEditing
        if (!state.isEditing) {
          state.selectedSubtaskId = null
          state.editingUserId = null
        }
      }

      return {
        state,
        computeRowWidth,
        computeSubtaskLeft,
        computeBarWidthValue,
        computeAddButtonLeft,
        colorForUser,
        lightenColor,
        getUserInitial,
        onProjectNameChange,
        onProjectDateChange,
        addMainTask,
        renameMainTask,
        deleteMainTask,
        addSubtask,
        updateSubtaskField,
        deleteSubtask,
        addUser,
        updateUser,
        deleteUser,
        onBarMouseDown,
        bestTextColor,
        selectSubtask,
        toggleEditing,
        startEditingUser,
        stopEditingUser,
        onRowDragStart,
        onRowDragEnd,
        onRowDragOver,
        onRowDragLeave,
        onRowDrop,
        onSubtaskDragStart,
        onSubtaskDragEnd,
        onSubtaskDragOver,
        onSubtaskDragLeave,
        onSubtaskDrop,
        DAY_PX,
        WEEK_PX: 7 * DAY_PX, // 7 days * 24px = 168px per week
      }
    },
    template: `
      <div>
        <div class="header">
          <div class="topbar">
            <div class="project-name" :contenteditable="state.isEditing" @blur="onProjectNameChange">{{ state.project ? state.project.name : 'loading…' }}</div>
            <label class="muted">Start</label>
            <input v-if="state.project" type="date" :value="state.project.start_date" @change="onProjectDateChange" :disabled="!state.isEditing" />
          </div>
          <div class="users">
            <span class="muted">Users</span>
            <template v-for="u in state.users" :key="u.id">
              <span class="user-pill" :class="{ editing: state.editingUserId === u.id }" @click="state.isEditing && startEditingUser(u.id)">
                <input v-if="state.isEditing && state.editingUserId === u.id" type="color" :value="u.color" @change="e=>updateUser(u,'color',e.target.value)" @click.stop />
                <span v-else class="user-color-preview" :style="{ background: u.color }"></span>
                <input v-if="state.isEditing && state.editingUserId === u.id" type="text" :value="u.name" @change="e=>updateUser(u,'name',e.target.value)" @blur="stopEditingUser" @keyup.enter="stopEditingUser" @click.stop />
                <span v-else class="user-name">{{ u.name }}</span>
                <button v-if="state.isEditing" class="btn delete-user-btn" @click.stop="()=>deleteUser(u)">×</button>
              </span>
            </template>
            <button v-if="state.isEditing" class="btn" @click="addUser">+ Add user</button>
            <button class="btn" @click="toggleEditing">{{ state.isEditing ? 'Done' : 'Edit' }}</button>
          </div>
        </div>

        <div class="gantt-wrapper" v-if="state.project">
          <div class="gantt-header-row">
            <div class="gantt-header-left">
              <span class="muted">Task</span>
              <button v-if="state.isEditing" class="add-task-btn-header" @click="addMainTask" title="Add task">
                <svg viewBox="0 0 6 6" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <path d="M3 0V6M0 3H6" stroke="currentColor" stroke-width="1" stroke-linecap="round"/>
                </svg>
              </button>
            </div>
            <div class="gantt-header-right">
              <div class="ruler-wrapper" :style="{ width: (state.daysHorizon * DAY_PX) + 'px', position: 'relative', minHeight: '50px' }">
                <div class="ruler" :style="{ width: (state.daysHorizon * DAY_PX) + 'px' }">
                  <div class="month-section" v-for="(m, mi) in state.months" :key="mi" :style="{ width: (m.weeks.length * WEEK_PX) + 'px' }">
                    <div class="month-header">{{ m.name }}</div>
                    <div class="month-weeks">
                      <div class="week-cell" v-for="(w, wi) in m.weeks" :key="wi" :class="{ 'first-week': wi === 0 }" :style="{ width: WEEK_PX + 'px' }">
                        {{ w.day }}
                      </div>
                    </div>
                  </div>
                </div>
                <!-- Final end date line with flag in header - only one flag for entire project -->
                <div v-if="state.endDate && state.actualEndDayPx > 0" class="end-date-line-header" :style="{ left: state.actualEndDayPx + 'px' }">
                  <div class="end-date-marker">
                    <svg class="flag-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                      <!-- Flag pole -->
                      <line x1="3" y1="3" x2="3" y2="21" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                      <!-- Checkered flag pattern -->
                      <rect x="4" y="4" width="4" height="4" fill="currentColor"/>
                      <rect x="12" y="4" width="4" height="4" fill="currentColor"/>
                      <rect x="8" y="8" width="4" height="4" fill="currentColor"/>
                      <rect x="16" y="8" width="4" height="4" fill="currentColor"/>
                      <rect x="4" y="12" width="4" height="4" fill="currentColor"/>
                      <rect x="12" y="12" width="4" height="4" fill="currentColor"/>
                      <rect x="8" y="16" width="4" height="4" fill="currentColor"/>
                      <rect x="16" y="16" width="4" height="4" fill="currentColor"/>
                    </svg>
                    <span class="end-date-text">{{ state.endDateFormatted }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="gantt-rows">
            <div class="gantt-row" v-for="(t, ti) in state.tasks" :key="t.id"
                 :draggable="state.isEditing"
                 @dragstart="(e)=>onRowDragStart(e,t,ti)"
                 @dragend="onRowDragEnd"
                 @dragover="(e)=>onRowDragOver(e,t,ti)"
                 @dragleave="onRowDragLeave"
                 @drop="(e)=>onRowDrop(e,t,ti)">
              <div class="gantt-row-left">
                <input v-if="state.isEditing" type="text" :value="t.name" @change="e=>renameMainTask(t,e)" />
                <span v-else class="task-name-text">{{ t.name }}</span>
                <div v-if="state.isEditing" class="row-actions">
                  <button class="btn" title="Add subtask" @click="()=>addSubtask(t)">+</button>
                  <button class="btn" title="Delete row" @click="()=>deleteMainTask(t)">×</button>
                </div>
              </div>
              <div class="gantt-row-right">
                <div class="timeline" :style="{ width: Math.max(computeRowWidth(t), state.daysHorizon * DAY_PX) + 'px' }">
                  <!-- Week start vertical lines -->
                  <div v-for="day in state.weekStartDays" :key="'week-' + day" 
                       class="week-line" 
                       :style="{ left: (day * DAY_PX) + 'px' }"></div>
                  <!-- Final end date line (no flag, flag is only in header) -->
                  <div v-if="state.endDate && state.actualEndDayPx > 0" class="end-date-line" :style="{ left: state.actualEndDayPx + 'px' }"></div>
                  <template v-for="(s, i) in t.subtasks" :key="s.id">
                    <div class="bar" :class="{ active: state.isEditing && state.selectedSubtaskId === s.id }" :data-subtask-id="s.id" :style="{ left: computeSubtaskLeft(t,i)+'px', width: computeBarWidthValue(s), background: colorForUser(s.user_id), color: bestTextColor(colorForUser(s.user_id)) }"
                         :draggable="state.isEditing && t.subtasks.length > 1"
                         @dragstart="(e)=>onSubtaskDragStart(e,t,s,i)"
                         @dragend="onSubtaskDragEnd"
                         @dragover="(e)=>onSubtaskDragOver(e,t,s,i)"
                         @dragleave="onSubtaskDragLeave"
                         @drop="(e)=>onSubtaskDrop(e,t,s,i)"
                         @mousedown="(e)=>onBarMouseDown(e,t,s,i)"
                         @click="(e)=>state.isEditing && selectSubtask(s.id)">
                      <!-- Name: text in default mode, input in active mode -->
                      <span v-if="!state.isEditing || state.selectedSubtaskId !== s.id" class="name-text">{{ s.name || 'Subtask' }}</span>
                      <input v-else class="name-input" type="text" :value="s.name" placeholder="Subtask" @change="e=>updateSubtaskField(s,'name',e.target.value)" @click.stop @focus.stop />
                      
                      <!-- User initial badge: only visible in non-edit mode when user is assigned -->
                      <span v-if="(!state.isEditing || state.selectedSubtaskId !== s.id) && s.user_id" class="user-initial-badge" :style="{ background: lightenColor(colorForUser(s.user_id), 0.16), color: bestTextColor(lightenColor(colorForUser(s.user_id), 0.16)) }">{{ getUserInitial(s.user_id) }}</span>
                      
                      <!-- User select: only visible in active mode -->
                      <select v-if="state.isEditing && state.selectedSubtaskId === s.id" class="user-select" :value="s.user_id" @change="e=>updateSubtaskField(s,'user_id', e.target.value ? Number(e.target.value) : null)" @click.stop>
                        <option :value="">Unassigned</option>
                        <option v-for="u in state.users" :key="u.id" :value="u.id">{{ u.name }}</option>
                      </select>
                      
                      <!-- Days input: only visible in active mode -->
                      <input v-if="state.isEditing && state.selectedSubtaskId === s.id" class="days-input" type="number" min="1" :value="s.duration_days" @change="e=>updateSubtaskField(s,'duration_days', Math.max(1, Number(e.target.value)))" @click.stop />
                      
                      <!-- Delete button: only visible in active mode -->
                      <button v-if="state.isEditing && state.selectedSubtaskId === s.id" class="btn" @click.stop="()=>deleteSubtask(s)">×</button>
                      <div class="resizer" style="cursor: ew-resize;"></div>
                    </div>
                  </template>
                  <button v-if="state.isEditing" class="btn add-subtask-btn" title="Add subtask" @click="()=>addSubtask(t)" :style="{ position: 'absolute', left: computeAddButtonLeft(t) + 'px', top: '50%', transform: 'translateY(-50%)' }">+</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    `
  }

  Vue.createApp(App).mount('#app')
})()


