<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Gantt Manager</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="./styles.css" />
  <script src="https://unpkg.com/vue@3.4.21/dist/vue.global.prod.js"></script>
  <script src="https://unpkg.com/dayjs@1.11.10/dayjs.min.js"></script>
  <script src="https://unpkg.com/dayjs@1.11.10/plugin/customParseFormat.js"></script>
  <style>
    .password-prompt {
      max-width: 400px;
      margin: 100px auto;
      padding: 30px;
      border: 1px solid var(--line);
      border-radius: 8px;
      text-align: center;
    }
    .password-prompt input {
      width: 100%;
      padding: 10px;
      margin: 10px 0;
      border: 1px solid var(--line);
      border-radius: 6px;
      font-size: 14px;
    }
    .password-prompt button {
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
    .password-prompt button:hover {
      opacity: 0.9;
    }
    .password-error {
      color: #d32f2f;
      font-size: 14px;
      margin-top: 5px;
    }
  </style>
</head>
<body>
  <div id="app"></div>
  <script src="./app.js"></script>
  <script>
    // Get slug from URL parameter
    const urlParams = new URLSearchParams(window.location.search);
    const slug = urlParams.get('slug');
    
    if (!slug) {
      document.getElementById('app').innerHTML = '<div class="password-prompt"><h2>Invalid Project</h2><p>No project slug provided.</p></div>';
    } else {
      // Store slug in window for app.js to access
      window.projectSlug = slug;
    }
  </script>
</body>
</html>

