// Get form elements
var loginForm = document.getElementById('loginForm');
var usernameInput = document.getElementById('username');
var passwordInput = document.getElementById('password');
var errorMessage = document.getElementById('error');

// Login function
loginForm.addEventListener('submit', function(e) {
    e.preventDefault();
    
    var username = usernameInput.value;
    var password = passwordInput.value;
    
    // Check if username and password are correct
    if (username === 'admin' && password === 'admin123') {
        alert('Login successful!');
        errorMessage.style.display = 'none';
        // Redirect to dashboard
        // window.location.href = 'dashboard.html';
    } else {
        errorMessage.style.display = 'block';
        passwordInput.value = '';
    }
});

// Hide error when typing
usernameInput.addEventListener('input', function() {
    errorMessage.style.display = 'none';
});

passwordInput.addEventListener('input', function() {
    errorMessage.style.display = 'none';
});