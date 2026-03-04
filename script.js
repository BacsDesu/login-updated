// Interactive animation for login page
document.addEventListener('DOMContentLoaded', function() {
    const loginContainer = document.getElementById('loginContainer');
    const loginLeft = document.getElementById('loginLeft');
    const loginRight = document.getElementById('loginRight');
    const loginForm = document.getElementById('loginForm');
    const bgImage = document.querySelector('.bg');
    
    // Always start with initial state on page load/refresh
    loginContainer.classList.add('initial-state');
    
    // Parallax effect on mouse move (only in initial state)
    document.addEventListener('mousemove', function(e) {
        if (!loginContainer.classList.contains('initial-state')) {
            // Reset any lingering transforms when not in initial state
            loginContainer.style.transform = '';
            if (bgImage) bgImage.style.transform = '';
            return;
        }
        
        const mouseX = e.clientX / window.innerWidth;
        const mouseY = e.clientY / window.innerHeight;
        
        // Parallax for login container (3D tilt effect)
        const tiltX = (mouseY - 0.5) * 10; // -5 to 5 degrees
        const tiltY = (mouseX - 0.5) * -10; // -5 to 5 degrees
        loginContainer.style.transform = `perspective(1000px) rotateX(${tiltX}deg) rotateY(${tiltY}deg)`;
        
        // Parallax for background image
        if (bgImage) {
            const moveX = (mouseX - 0.5) * 30;
            const moveY = (mouseY - 0.5) * 30;
            bgImage.style.transform = `scale(1.1) translate(${moveX}px, ${moveY}px)`;
        }
    });
    
    // Click handler for login-left
    loginLeft.addEventListener('click', function(e) {
        // Don't trigger if already expanded or expanding
        if (loginContainer.classList.contains('expanded') || 
            loginContainer.classList.contains('expanding')) {
            return;
        }
        
        // Reset transforms before expanding
        loginContainer.style.transform = '';
        if (bgImage) {
            bgImage.style.transform = '';
        }
        
        // Trigger animation
        loginContainer.classList.remove('initial-state');
        loginContainer.classList.add('expanding');
        
        // After animation completes, set final state
        setTimeout(function() {
            loginContainer.classList.remove('expanding');
            loginContainer.classList.add('expanded');
            
            // Focus on email field
            const emailInput = document.getElementById('email');
            if (emailInput) {
                emailInput.focus();
            }
        }, 800);
    });
    
    // Add cursor pointer and hover effect
    loginLeft.style.cursor = 'pointer';
    
    if (loginForm) {
        // Add input focus effects
        const inputs = loginForm.querySelectorAll('input');
        inputs.forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.classList.add('focused');
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.classList.remove('focused');
            });
        });
        
        // Form submission
        loginForm.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            const email = this.querySelector('#email').value;
            const password = this.querySelector('#password').value;
            
            // Basic validation
            if (!email || !password) {
                e.preventDefault();
                alert('Please fill in all fields');
                return false;
            }
            
            // Show loading state
            submitBtn.textContent = 'Logging in...';
            submitBtn.disabled = true;
        });
    }
});