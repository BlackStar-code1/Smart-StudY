// ===== AUTOCARE PRO - MAIN JS =====

// Navbar scroll effect
const navbar = document.getElementById('navbar');
if (navbar) {
  window.addEventListener('scroll', () => {
    navbar.classList.toggle('scrolled', window.scrollY > 50);
  });
}

// Mobile hamburger
const hamburger = document.getElementById('hamburger');
const navLinks = document.querySelector('.nav-links');
if (hamburger) {
  hamburger.addEventListener('click', () => {
    navLinks.classList.toggle('open');
  });
}

// Auto-dismiss flash messages
const flash = document.querySelector('.flash-msg');
if (flash) {
  setTimeout(() => flash.remove(), 5000);
}

// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(a => {
  a.addEventListener('click', e => {
    const target = document.querySelector(a.getAttribute('href'));
    if (target) {
      e.preventDefault();
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      navLinks?.classList.remove('open');
    }
  });
});

// OTP auto-advance
document.querySelectorAll('.otp-inputs input').forEach((input, i, inputs) => {
  input.addEventListener('input', () => {
    if (input.value.length >= 1 && i < inputs.length - 1) {
      inputs[i + 1].focus();
    }
  });
  input.addEventListener('keydown', e => {
    if (e.key === 'Backspace' && !input.value && i > 0) {
      inputs[i - 1].focus();
    }
  });
});

// Combine OTP inputs before form submit
const otpForm = document.getElementById('otpForm');
if (otpForm) {
  otpForm.addEventListener('submit', e => {
    const inputs = document.querySelectorAll('.otp-inputs input');
    const otp = [...inputs].map(i => i.value).join('');
    document.getElementById('otpHidden').value = otp;
  });
}

// Intersection Observer for animations
const observer = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      entry.target.style.opacity = '1';
      entry.target.style.transform = 'translateY(0)';
    }
  });
}, { threshold: 0.1 });

document.querySelectorAll('.service-card, .step, .review-card, .stat-card').forEach(el => {
  el.style.opacity = '0';
  el.style.transform = 'translateY(20px)';
  el.style.transition = 'opacity .5s ease, transform .5s ease';
  observer.observe(el);
});

// Mobile sidebar toggle (dashboard)
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebar = document.querySelector('.sidebar');
if (sidebarToggle && sidebar) {
  sidebarToggle.addEventListener('click', () => {
    sidebar.classList.toggle('open');
  });
}

// Confirm delete dialogs
document.querySelectorAll('[data-confirm]').forEach(btn => {
  btn.addEventListener('click', e => {
    if (!confirm(btn.dataset.confirm)) e.preventDefault();
  });
});


document.querySelectorAll('.star-rating label').forEach(label => {
  label.addEventListener('mouseover', function () {
    const val = this.getAttribute('for').replace('star', '');
    highlightStars(val);
  });
});
function highlightStars(val) {
  document.querySelectorAll('.star-rating label').forEach(l => {
    const v = parseInt(l.getAttribute('for').replace('star', ''));
    l.style.color = v <= val ? '#ffd700' : '#555';
  });
}