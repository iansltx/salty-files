<script defer>
    let authToken = '';

    function urlencode(data) {
        return Object.keys(data)
            .map(key => encodeURIComponent(key) + '=' + encodeURIComponent(data[key]))
            .join('&');
    }

    function showError(message) {
        const errorBox = document.getElementById('errorBox');
        errorBox.style.display = 'block';
        errorBox.innerText = message;
        document.getElementById('successBox').style.display = 'none';
    }

    function showSuccess(message) {
        const successBox = document.getElementById('successBox');
        successBox.style.display = 'block';
        successBox.innerText = message;
        document.getElementById('errorBox').style.display = 'none';
    }

    function hideMessages() {
        document.getElementById('successBox').style.display = 'none';
        document.getElementById('errorBox').style.display = 'none';
    }

    async function showKey() {
        const response = await fetch('/me/key', {
            headers: {Authorization: 'Bearer ' + authToken}
        });
        const json = await response.json();

        if (!response.ok) {
            showError(json.message);
            return;
        }

        alert('Key: ' + json.key);
    }

    async function login() {
        const response = await fetch('/sessions', {
            method: 'POST',
            headers: {'Content-type': 'application/x-www-form-urlencoded'},
            body: urlencode({
                username: document.getElementById('loginUsername').value,
                password: document.getElementById('loginPassword').value
            })
        });

        document.getElementById('loginPassword').value = '';

        const json = await response.json();

        if (!response.ok) {
            showError(json.message);
            return;
        }

        authToken = json.token;
        showSuccess('Login successful!');
        await showFiles();
        setTimeout(() => { hideMessages(); }, 5000);
    }

    async function signUp() {
        const response = await fetch('/users', {
            method: 'POST',
            headers: {'Content-type': 'application/x-www-form-urlencoded'},
            body: urlencode({
                username: document.getElementById('signupUsername').value,
                password: document.getElementById('signupPassword').value,
                password_confirm: document.getElementById('signupPasswordConfirm').value
            })
        });

        document.getElementById('signupPassword').value = '';
        document.getElementById('signupPasswordConfirm').value = '';

        const json = await response.json();

        if (!response.ok) {
            showError(json.message);
            return;
        }

        authToken = json.token;
        showSuccess('Your account has been created. Welcome!');
        await showFiles();
        setTimeout(() => { hideMessages(); }, 5000);
    }

    async function executePasswordReset() {
        const response = await fetch('/password_resets', {
            method: 'POST',
            headers: {'Content-type': 'application/x-www-form-urlencoded'},
            body: urlencode({
                username: document.getElementById('resetUsername').value,
                key: document.getElementById('resetKey').value,
                password: document.getElementById('resetPassword').value,
                password_confirm: document.getElementById('resetPasswordConfirm').value
            })
        });

        document.getElementById('resetKey').value = '';
        document.getElementById('resetPassword').value = '';
        document.getElementById('resetPasswordConfirm').value = '';

        const json = await response.json();

        if (!response.ok) {
            showError(json.message);
            return;
        }

        showSuccess('Your password has been reset. You may now log in with your new password.');

        document.getElementById('pwReset').style.display = 'none';
        document.getElementById('login').style.display = 'block';

        setTimeout(() => { hideMessages(); }, 5000);
    }

    function showSignup() {
        document.getElementById('login').style.display = 'none';
        document.getElementById('signup').style.display = 'block';
    }

    function showResetPassword() {
        document.getElementById('login').style.display = 'none';
        document.getElementById('pwReset').style.display = 'block';
    }

    async function shareFile(e) {
        // button -> td -> tr
        const id = e.target.parentElement.parentElement.getAttribute('data-file-id');
        const username = prompt('Please enter the username of who you want to share the file with');
        const response = await fetch(`/files/${id}/share/${username}`, {
            method: 'POST',
            headers: {Authorization: 'Bearer ' + authToken}
        });

        if (!response.ok) {
            showError((await response.json()).message);
            return;
        }

        showSuccess('File has been shared to ' + username);
        setTimeout(() => { hideMessages(); }, 5000);
    }

    async function unshareFile(e) {
        // button -> td -> tr
        const id = e.target.parentElement.parentElement.getAttribute('data-file-id');
        const username = prompt('Please enter the username of who you want to remove file sharing from for this file');
        const response = await fetch(`/files/${id}/share/${username}`, {
            method: 'DELETE',
            headers: {Authorization: 'Bearer ' + authToken}
        });

        if (!response.ok) {
            showError((await response.json()).message);
            return;
        }

        showSuccess('File has been unshared from ' + username);
        setTimeout(() => { hideMessages(); }, 5000);
    }

    async function deleteFile(e) {
        // button -> td -> tr
        const id = e.target.parentElement.parentElement.getAttribute('data-file-id');
        if (!confirm('Are you sure you want to delete this file?')) {
            return;
        }

        const response = await fetch(`/files/${id}`, {
            method: 'DELETE',
            headers: {Authorization: 'Bearer ' + authToken}
        });

        if (!response.ok) {
            showError((await response.json()).message);
            return;
        }

        showSuccess('File has been deleted');
        setTimeout(() => { hideMessages(); }, 5000);
    }

    async function downloadFile(e) {
        const id = e.target.parentElement.getAttribute('data-file-id');
        const response = await fetch(`/files/${id}`, {headers: {Authorization: 'Bearer ' + authToken}});

        // from https://stackoverflow.com/a/42274086/2476827
        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = e.target.innerText;
        // we need to append the element to the dom -> otherwise it will not work in firefox
        document.body.appendChild(a);
        a.click();
        a.remove();  //afterwards we remove the element again
    }

    async function showFiles() {
        document.getElementById('login').style.display = 'none';
        document.getElementById('signup').style.display = 'none';
        document.getElementById('pwReset').style.display = 'none';
        document.getElementById('loggedIn').style.display = 'block';

        const filesTable = document.getElementById('filesTable');
        filesTable.innerHTML = '';

        const response = await fetch('/files', {
            headers: {Authorization: 'Bearer ' + authToken}
        });
        const json = await response.json();

        if (!response.ok) {
            showError(json.message);
            return;
        }

        filesTable.innerHTML = json.map(getFileTableRow).join('');
    }

    function getFileTableRow(file) {
        const sharedWith = file.shared_with.map(obj => obj.username).join(', ');
        const actions = !file.owner.is_self ? '' :
            '<button class="btn btn-success" onclick="shareFile(event); return false;">Share</button>' +
            '<button class="btn btn-warning" onclick="unshareFile(event); return false;">Unshare</button>' +
            '<button class="btn btn-danger" onclick="deleteFile(event); return false;">Delete</button>';

        return `<tr data-file-id="${file.id}"><td style="color: blue" onclick="downloadFile(event); return false;">${file.filename}</td>` +
            `<td>${file.owner.username}</td><td>${file.size}</td><td>${sharedWith}</td><td>${actions}</td>`;
    }

    async function uploadFile() {
        const fileUpload = document.getElementById('fileUpload');
        const formData = new FormData();
        formData.append('file', fileUpload.files[0]);

        const response = await fetch('/files', {
            method: 'POST',
            body: formData,
            headers: {Authorization: 'Bearer ' + authToken}
        });
        const json = await response.json();

        if (!response.ok) {
            showError(json.message);
            return;
        }

        document.getElementById('filesTable').innerHTML =
            getFileTableRow(json) + document.getElementById('filesTable').innerHTML;
    }
</script>

<div class="alert alert-danger" role="alert" style="display: none" id="errorBox">
</div>

<div class="alert alert-success" role="alert" style="display: none" id="successBox">
</div>

<div id="loggedIn" style="display: none">
    <button type="button" class="btn btn-warning" onclick="showKey(); return false;">Show Key</button>
    <h2>Files</h2>
    <label for="fileUpload">Upload a file: </label><input type="file" id="fileUpload" onchange="setTimeout(uploadFile, 100)" />
    <p id="uploading" style="display: none">Uploading...</p>
    <table>
        <thead>
        <tr>
            <th>Filename</th><th>Owner</th><th>Size</th><th>Shared With</th><th>Actions</th>
        </tr>
        </thead>
        <tbody id="filesTable">
        </tbody>
    </table>
</div>

<form id="login">
    <div class="form-group">
        <label for="loginUsername">Username</label>
        <input type="text" class="form-control" id="loginUsername">
    </div>
    <div class="form-group">
        <label for="loginPassword">Password</label>
        <input type="password" class="form-control" id="loginPassword">
    </div>
    <button type="submit" class="btn btn-primary" onclick="login(); return false;">Log In</button>
    <button type="button" class="btn btn-link" onclick="showResetPassword(); return false;">Forgot Password?</button>
    <button type="button" class="btn btn-link" onclick="showSignup(); return false;">Need an account?</button>
</form>

<form id="pwReset" style="display: none">
    <div class="form-group">
        <label for="resetUsername">Username</label>
        <input type="text" class="form-control" id="resetUsername">
    </div>
    <div class="form-group">
        <label for="resetKey">Key</label>
        <input type="password" class="form-control" id="resetKey">
    </div>
    <div class="form-group">
        <label for="resetPassword">New Password</label>
        <input type="password" class="form-control" id="resetPassword">
    </div>
    <div class="form-group">
        <label for="resetPasswordConfirm">Confirm New Password</label>
        <input type="password" class="form-control" id="resetPasswordConfirm">
    </div>
    <button type="submit" class="btn btn-primary" onclick="executePasswordReset(); return false;">Reset</button>
</form>

<form id="signup" style="display: none">
    <div class="form-group">
        <label for="signupUsername">Username</label>
        <input type="text" class="form-control" id="signupUsername">
    </div>
    <div class="form-group">
        <label for="signupPassword">Password</label>
        <input type="password" class="form-control" id="signupPassword">
    </div>
    <div class="form-group">
        <label for="signupPasswordConfirm">Confirm Password</label>
        <input type="password" class="form-control" id="signupPasswordConfirm">
    </div>
    <button type="submit" class="btn btn-primary" onclick="signUp(); return false;">Sign Up</button>
</form>
