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
        selectedSubtaskId: null,
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
        for (const t of state.tasks) {
          const taskDays = t.subtasks.reduce((a, s) => a + Number(s.duration_days || 0), 0)
          const totalDays = Number(t.start_offset_days || 0) + taskDays
          if (totalDays > maxDays) maxDays = totalDays
        }
        state.daysHorizon = Math.max(maxDays, 30)
        computeMonths()
      }

      function computeMonths() {
        if (!state.project) return
        const start = dayjs(state.project.start_date, 'YYYY-MM-DD')
        const months = []
        const monthMap = new Map()
        
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
          }
        }
        
        // Convert to array format, sorted by date
        const sortedMonths = Array.from(monthMap.entries()).sort((a, b) => a[0].localeCompare(b[0]))
        state.months = sortedMonths.map(([key, data]) => ({
          name: data.name,
          weeks: Array.from(data.weekStarts).sort((a, b) => a - b).map(day => ({ day }))
        }))
      }

      async function onProjectNameChange(e) {
        const name = e.target.innerText.trim() || 'untitled'
        await api.updateProject({ id: state.project.id, name })
        state.project.name = name
      }

      async function onProjectDateChange(e) {
        const start = e.target.value
        await api.updateProject({ id: state.project.id, start_date: start })
        state.project.start_date = start
        computeMonths()
      }

      async function addMainTask() {
        const res = await api.addMainTask({ project_id: state.project.id, name: 'Main task' })
        await loadProject()
      }

      async function renameMainTask(task, e) {
        await api.updateMainTask({ id: task.id, name: e.target.value })
        task.name = e.target.value
      }

      async function deleteMainTask(task) {
        if (!confirm('Delete main task and all its subtasks?')) return
        await api.deleteMainTask({ id: task.id })
        await loadProject()
      }

      async function addSubtask(task) {
        await api.addSubtask({ main_task_id: task.id, duration_days: 7 })
        await loadProject()
      }

      async function updateSubtaskField(sub, field, value) {
        const payload = { id: sub.id }
        payload[field] = value
        await api.updateSubtask(payload)
        sub[field] = value
        recomputeHorizon()
      }

      async function deleteSubtask(sub) {
        if (!confirm('Delete subtask?')) return
        await api.deleteSubtask({ id: sub.id })
        await loadProject()
      }

      // Users
      async function addUser() {
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
        await api.updateUser({ id: user.id, [field]: value })
        user[field] = value
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

      // Drag-resize and drag-move
      let dragging = null
      let clickStartPos = null
      let clickedSubtaskId = null
      function onBarMouseDown(e, task, sub, subIndex) {
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
        
        e.preventDefault()
        const startX = e.clientX
        const currentLeft = computeSubtaskLeft(task, subIndex)
        
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
        })
      })

      return {
        state,
        computeRowWidth,
        computeSubtaskLeft,
        computeBarWidthValue,
        computeAddButtonLeft,
        colorForUser,
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
        DAY_PX,
        WEEK_PX: 7 * DAY_PX, // 7 days * 24px = 168px per week
      }
    },
    template: `
      <div>
        <div class="header">
          <div class="topbar">
            <div class="project-name" contenteditable="true" @blur="onProjectNameChange">{{ state.project ? state.project.name : 'loading…' }}</div>
            <label class="muted">Start</label>
            <input v-if="state.project" type="date" :value="state.project.start_date" @change="onProjectDateChange" />
            <button class="btn" @click="addMainTask">+ Main task</button>
          </div>
          <div class="users">
            <span class="muted">Users</span>
            <template v-for="u in state.users" :key="u.id">
              <span class="user-pill">
                <input type="color" :value="u.color" @change="e=>updateUser(u,'color',e.target.value)" />
                <input type="text" :value="u.name" @change="e=>updateUser(u,'name',e.target.value)" />
                <button class="btn" @click="()=>deleteUser(u)">×</button>
              </span>
            </template>
            <button class="btn" @click="addUser">+ Add user</button>
          </div>
        </div>

        <div class="gantt" v-if="state.project">
          <div class="left-col">
            <div class="header muted">Task</div>
            <div class="item" v-for="t in state.tasks" :key="t.id">
              <input type="text" :value="t.name" @change="e=>renameMainTask(t,e)" />
              <div class="row-actions">
                <button class="btn" title="Add subtask" @click="()=>addSubtask(t)">+</button>
                <button class="btn" title="Delete row" @click="()=>deleteMainTask(t)">×</button>
              </div>
            </div>
          </div>
          <div class="right-col">
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
            <div class="rowline" v-for="t in state.tasks" :key="'line-'+t.id">
              <div class="timeline" :style="{ width: Math.max(computeRowWidth(t), state.daysHorizon * DAY_PX) + 'px' }">
                <template v-for="(s, i) in t.subtasks" :key="s.id">
                  <div class="bar" :class="{ active: state.selectedSubtaskId === s.id }" :data-subtask-id="s.id" :style="{ left: computeSubtaskLeft(t,i)+'px', width: computeBarWidthValue(s), background: colorForUser(s.user_id), color: bestTextColor(colorForUser(s.user_id)) }" @mousedown="(e)=>onBarMouseDown(e,t,s,i)">
                    <!-- Name: text in default mode, input in active mode -->
                    <span v-if="state.selectedSubtaskId !== s.id" class="name-text">{{ s.name || 'Subtask' }}</span>
                    <input v-else class="name-input" type="text" :value="s.name" placeholder="Subtask" @change="e=>updateSubtaskField(s,'name',e.target.value)" @click.stop @focus.stop />
                    
                    <!-- User select: only visible in active mode -->
                    <select v-if="state.selectedSubtaskId === s.id" class="user-select" :value="s.user_id" @change="e=>updateSubtaskField(s,'user_id', e.target.value ? Number(e.target.value) : null)" @click.stop>
                      <option :value="">Unassigned</option>
                      <option v-for="u in state.users" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                    
                    <!-- Days input: only visible in active mode -->
                    <input v-if="state.selectedSubtaskId === s.id" class="days-input" type="number" min="1" :value="s.duration_days" @change="e=>updateSubtaskField(s,'duration_days', Math.max(1, Number(e.target.value)))" @click.stop />
                    
                    <!-- Delete button: only visible in active mode -->
                    <button v-if="state.selectedSubtaskId === s.id" class="btn" @click.stop="()=>deleteSubtask(s)">×</button>
                    <div class="resizer" style="cursor: ew-resize;"></div>
                  </div>
                </template>
                <button class="btn add-subtask-btn" title="Add subtask" @click="()=>addSubtask(t)" :style="{ position: 'absolute', left: computeAddButtonLeft(t) + 'px', top: '50%', transform: 'translateY(-50%)' }">+</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    `
  }

  Vue.createApp(App).mount('#app')
})()


