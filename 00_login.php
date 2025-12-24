<?php 
session_start();
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Login • RestaurantPOS</title>
  <link rel="stylesheet" href="09_style.css"/>
</head>
<body style="min-height:100vh;display:grid;place-items:center;background:#f3f4f6">
  <div class="card" style="padding:1.25rem;border-radius:1rem;max-width:100%;width:100%">
    
    <form id="loginForm" method="POST" action="00_login_process.php">
      <h1 style="font-weight:700;font-size:2.0rem;margin:0 0 .75rem">Sign in</h1>

      <label class="muted" style="font-size:1.5rem">Name</label>
      <input id="name" name="name" class="input" placeholder="full name" style="margin:.25rem 0 1rem"/>

      <label class="muted" style="font-size:1.5rem">Role</label>
      <select id="role" name="role" class="input" style="margin:.25rem 0 1rem">
        <option value="staff">Staff</option>
        <option value="manager">Manager</option>
      </select>

      <div id="codeWrap">
        <label class="muted" id="codeLabel" style="font-size:1.5rem;display:flex;justify-content:space-between;align-items:center">
          Staff Code
          <button id="togglePwd" type="button" class="btn sm">Show</button>
        </label>
        <input id="roleCode" name="code" type="password" class="input" placeholder="Enter code" style="margin:.25rem 0 1rem"/>
      </div>

      <p id="msg" style="color:#dc2626;height:1.25rem;margin:.25rem 0 .75rem;font-size:.9rem">
        <?php
          if (isset($_GET['error'])) {
            // htmlspecialchars() prevents security issues
            echo htmlspecialchars($_GET['error']);
          }
        ?>
      </p>

      <button id="login" type="submit" class="btn" style="width:100%;background:#4f46e5;color:#fff;font-weight:800">Login</button>
    
    </form>
  </div>

<script type="module">

  const $ = id=>document.getElementById(id);
  const role=$('role'), codeEl=$('roleCode'), codeLbl=$('codeLabel'), msg=$('msg');

  function updateRole(){ const mgr=role.value==='manager';
    codeLbl.firstChild.textContent=mgr?'Manager Code':'Staff Code';
    codeEl.placeholder=mgr?'Enter manager code':'Enter staff code'; msg.textContent=''; codeEl.value='';}
  role.addEventListener('change',updateRole); updateRole();

  $('togglePwd').onclick=()=>{ const isPwd=codeEl.type==='password'; codeEl.type=isPwd?'text':'password'; $('togglePwd').textContent=isPwd?'Hide':'Show'; };


  // It checks the fields before sending to the server.
  $('loginForm').addEventListener('submit', (event) => {
    const name=($('name').value||'').trim();
    const code=codeEl.value.trim();
    msg.textContent = ''; // Clear old errors

    // Stop form from submitting
    if(!name) {
      msg.textContent='Please enter your name.';
      event.preventDefault(); 
    } else if (!code) {
      msg.textContent='Please enter your code.';
      event.preventDefault();
    }
  });
</script>

</body>
</html>