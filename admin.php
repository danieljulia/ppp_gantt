<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin - Gantt Manager</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="./styles.css" />
  <script src="https://unpkg.com/vue@3.4.21/dist/vue.global.prod.js"></script>
  <style>
    body {
      max-width: 1200px;
      margin: 0 auto;
      padding: 20px;
    }
    .login-form {
      max-width: 400px;
      margin: 100px auto;
      padding: 30px;
      border: 1px solid var(--line);
      border-radius: 8px;
    }
    .login-form input {
      width: 100%;
      padding: 10px;
      margin: 10px 0;
      border: 1px solid var(--line);
      border-radius: 6px;
      font-size: 14px;
    }
    .login-form button {
      width: 100%;
      padding: 10px;
      margin-top: 10px;
      background: var(--accent);
      color: white;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-weight: 600;
    }
    .login-form button:hover {
      opacity: 0.9;
    }
    .admin-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 30px;
    }
    .admin-header h1 {
      margin: 0;
      font-size: 24px;
    }
    .projects-list {
      border: 1px solid var(--line);
      border-radius: 8px;
      overflow: hidden;
    }
    .project-item {
      display: flex;
      align-items: center;
      padding: 15px;
      border-bottom: 1px solid var(--line);
      gap: 15px;
    }
    .project-item:last-child {
      border-bottom: none;
    }
    .project-item-info {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 5px;
    }
    .project-item-name {
      font-weight: 600;
      font-size: 16px;
    }
    .project-item-url {
      font-size: 12px;
      color: var(--muted);
      font-family: monospace;
    }
    .project-item-url a {
      color: var(--accent);
      text-decoration: none;
    }
    .project-item-url a:hover {
      text-decoration: underline;
    }
    .project-item-actions {
      display: flex;
      gap: 10px;
      align-items: center;
    }
    .add-project-form {
      padding: 15px;
      border-bottom: 1px solid var(--line);
      background: #fafafa;
      display: flex;
      gap: 10px;
      align-items: flex-end;
      flex-wrap: wrap;
    }
    .add-project-form input {
      padding: 8px;
      border: 1px solid var(--line);
      border-radius: 6px;
      font-size: 14px;
    }
    .add-project-form input[type="text"] {
      flex: 1;
      min-width: 200px;
    }
    .add-project-form input[type="text"].password-input {
      width: 150px;
    }
    .project-item-info input[type="text"] {
      padding: 4px 8px;
      border: 1px solid var(--line);
      border-radius: 4px;
      font-size: 14px;
      width: 100%;
      max-width: 300px;
    }
    .project-item-info input.password-input {
      font-family: monospace;
      font-size: 12px;
      max-width: 200px;
    }
    .project-item-name input {
      font-weight: 600;
      font-size: 16px;
    }
    .editing-password {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .add-project-form button {
      padding: 8px 16px;
      background: var(--accent);
      color: white;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-weight: 600;
    }
    .add-project-form button:hover {
      opacity: 0.9;
    }
    .error {
      color: #d32f2f;
      font-size: 14px;
      margin-top: 5px;
    }
    .password-display {
      font-size: 12px;
      color: var(--muted);
      font-family: monospace;
    }
  </style>
</head>
<body>
  <div id="app"></div>
  <script>
    const { createApp } = Vue;
    
    createApp({
      template: `
        <div>
          <div v-if="!authenticated" class="login-form">
            <h2>Admin Login</h2>
            <input type="password" v-model="password" placeholder="Admin password" @keyup.enter="login" />
            <div v-if="loginError" class="error">{{ loginError }}</div>
            <button @click="login">Login</button>
          </div>
          
          <div v-else>
            <div class="admin-header">
              <h1>Projects Management</h1>
              <button class="btn" @click="logout">Logout</button>
            </div>
            
            <div v-if="error" class="error">{{ error }}</div>
            
            <div class="projects-list">
              <div class="add-project-form">
                <input type="text" v-model="newProjectName" placeholder="Project name" @keyup.enter="addProject" />
                <input type="text" class="password-input" v-model="newProjectPassword" placeholder="Project password (optional)" @keyup.enter="addProject" />
                <button @click="addProject">Add Project</button>
              </div>
              
              <div v-if="loading">Loading...</div>
              <div v-else>
                <div v-for="p in projects" :key="p.id" class="project-item">
                  <div class="project-item-info">
                    <div class="project-item-name">
                      <input v-if="editingProjectId === p.id" type="text" v-model="editingProjectName" @blur="saveProject(p.id)" @keyup.enter="saveProject(p.id)" />
                      <span v-else>{{ p.name }}</span>
                    </div>
                    <div class="project-item-url">
                      URL: <a :href="getProjectUrl(p.slug)" target="_blank">{{ getProjectUrl(p.slug) }}</a>
                    </div>
                    <div class="editing-password">
                      <span style="font-size: 12px; color: var(--muted);">Password:</span>
                      <input v-if="editingProjectId === p.id" type="text" class="password-input" v-model="editingProjectPassword" placeholder="No password" @blur="saveProject(p.id)" @keyup.enter="saveProject(p.id)" />
                      <span v-else class="password-display">{{ p.password || '(none)' }}</span>
                    </div>
                  </div>
                  <div class="project-item-actions">
                    <button v-if="editingProjectId === p.id" class="btn" @click="cancelEdit()">Cancel</button>
                    <button v-else class="btn" @click="startEdit(p)">Edit</button>
                    <button class="btn" @click="deleteProject(p.id)">Delete</button>
                  </div>
                </div>
                <div v-if="projects.length === 0" style="padding: 20px; text-align: center; color: var(--muted);">
                  No projects yet. Add one above.
                </div>
              </div>
            </div>
          </div>
        </div>
      `,
      data() {
        return {
          authenticated: false,
          password: '',
          loginError: '',
          projects: [],
          loading: false,
          newProjectName: '',
          newProjectPassword: '',
          error: '',
          editingProjectId: null,
          editingProjectName: '',
          editingProjectPassword: ''
        }
      },
      mounted() {
        this.checkAuth();
      },
      methods: {
        async checkAuth() {
          try {
            const res = await fetch('api.php?r=list_projects');
            if (res.ok) {
              const data = await res.json();
              this.authenticated = true;
              this.projects = data.projects || [];
            } else {
              this.authenticated = false;
            }
          } catch (e) {
            this.authenticated = false;
          }
        },
        async login() {
          this.loginError = '';
          try {
            const res = await fetch('api.php?r=admin_login', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ password: this.password })
            });
            const data = await res.json();
            if (res.ok) {
              this.authenticated = true;
              this.password = '';
              await this.loadProjects();
            } else {
              this.loginError = data.error || 'Invalid password';
            }
          } catch (e) {
            this.loginError = 'Error logging in';
          }
        },
        async logout() {
          try {
            await fetch('api.php?r=admin_logout', { method: 'POST' });
            this.authenticated = false;
            this.projects = [];
          } catch (e) {
            // Ignore
          }
        },
        async loadProjects() {
          this.loading = true;
          try {
            const res = await fetch('api.php?r=list_projects');
            const data = await res.json();
            if (res.ok) {
              this.projects = data.projects || [];
            }
          } catch (e) {
            this.error = 'Error loading projects';
          } finally {
            this.loading = false;
          }
        },
        async addProject() {
          if (!this.newProjectName.trim()) {
            this.error = 'Project name is required';
            return;
          }
          this.error = '';
          try {
            const res = await fetch('api.php?r=create_project', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                name: this.newProjectName,
                password: this.newProjectPassword
              })
            });
            const data = await res.json();
            if (res.ok) {
              this.newProjectName = '';
              this.newProjectPassword = '';
              await this.loadProjects();
            } else {
              this.error = data.error || 'Error creating project';
            }
          } catch (e) {
            this.error = 'Error creating project';
          }
        },
        async deleteProject(id) {
          if (!confirm('Are you sure you want to delete this project? This will delete all tasks, subtasks, and users.')) {
            return;
          }
          try {
            const res = await fetch('api.php?r=delete_project', {
              method: 'DELETE',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ id })
            });
            if (res.ok) {
              await this.loadProjects();
            } else {
              this.error = 'Error deleting project';
            }
          } catch (e) {
            this.error = 'Error deleting project';
          }
        },
        getProjectUrl(slug) {
          return window.location.origin + window.location.pathname.replace('admin.php', '') + 'project.php?slug=' + encodeURIComponent(slug);
        },
        startEdit(project) {
          this.editingProjectId = project.id;
          this.editingProjectName = project.name;
          this.editingProjectPassword = project.password || '';
        },
        cancelEdit() {
          this.editingProjectId = null;
          this.editingProjectName = '';
          this.editingProjectPassword = '';
        },
        async saveProject(id) {
          const project = this.projects.find(p => p.id === id);
          if (!project) return;
          
          const trimmedName = this.editingProjectName.trim();
          const trimmedPassword = this.editingProjectPassword.trim();
          
          const updates = {};
          if (trimmedName !== project.name) {
            updates.name = trimmedName;
          }
          // Always update password if it changed (even if clearing to empty)
          const currentPassword = project.password || '';
          if (trimmedPassword !== currentPassword) {
            updates.password = trimmedPassword;
          }
          
          if (Object.keys(updates).length === 0) {
            this.cancelEdit();
            return;
          }
          
          if (updates.name !== undefined && !updates.name) {
            this.error = 'Project name cannot be empty';
            return;
          }
          
          this.error = '';
          try {
            const res = await fetch('api.php?r=update_project', {
              method: 'PUT',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                id: id,
                ...updates
              })
            });
            const data = await res.json();
            if (res.ok) {
              this.cancelEdit();
              await this.loadProjects();
            } else {
              this.error = data.error || 'Error updating project';
            }
          } catch (e) {
            this.error = 'Error updating project';
          }
        }
      }
    }).mount('#app');
  </script>
</body>
</html>
