// Features data
var featuresData = [
    { icon: '', title: 'Digital Material Movement', desc: 'Track all inventory movements in real-time with our advanced digital ledger system' },
    { icon: '', title: 'Barcode-Driven Audit Trail', desc: 'Scan and track every item with barcode technology for complete accountability' },
    { icon: '', title: 'Smart Restocking Workflow', desc: 'Automated alerts and smart suggestions for efficient inventory restocking' },
    { icon: '', title: 'Real-Time Analytics', desc: 'Get insights and reports on your inventory status instantly with powerful analytics' },
    { icon: '', title: 'Secure & Reliable', desc: 'Enterprise-grade security to protect your valuable inventory data' },
    { icon: '', title: 'Fast Performance', desc: 'Lightning-fast system designed for high-volume operations and seamless workflow' }
];


// Generate features grid
function generateFeatures() {
    var featuresGrid = document.getElementById('featuresGrid');
    var html = '';
    
    for (var i = 0; i < featuresData.length; i++) {
        html += '<div class="feature-card">';
        html += '<div class="feature-icon">' + featuresData[i].icon + '</div>';
        html += '<h3>' + featuresData[i].title + '</h3>';
        html += '<p>' + featuresData[i].desc + '</p>';
        html += '</div>';
    }
    
    featuresGrid.innerHTML = html;
}


var navLinks = document.querySelectorAll('nav a[href^="#"]');

navLinks.forEach(function(link) {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        var targetId = this.getAttribute('href');
        var targetSection = document.querySelector(targetId);
        
        if (targetSection) {
            targetSection.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    });
});

// Contact form submission
var contactForm = document.getElementById('contactForm');

contactForm.addEventListener('submit', function(e) {
    e.preventDefault();
    alert('Thank you for your message! We will get back to you soon.');
    this.reset();
});

// Header scroll effect
window.addEventListener('scroll', function() {
    var header = document.getElementById('header');
    if (window.scrollY > 50) {
        header.classList.add('scrolled');
    } else {
        header.classList.remove('scrolled');
    }
});

// Mobile menu toggle
var mobileToggle = document.getElementById('mobileToggle');
var navMenu = document.getElementById('navMenu');

mobileToggle.addEventListener('click', function() {
    if (navMenu.style.display === 'flex') {
        navMenu.style.display = 'none';
    } else {
        navMenu.style.display = 'flex';
        navMenu.style.flexDirection = 'column';
        navMenu.style.position = 'absolute';
        navMenu.style.top = '70px';
        navMenu.style.right = '20px';
        navMenu.style.background = 'white';
        navMenu.style.padding = '20px';
        navMenu.style.borderRadius = '10px';
        navMenu.style.boxShadow = '0 5px 20px rgba(0, 0, 0, 0.2)';
    }
});

// Intersection Observer for animations
var observerOptions = {
    threshold: 0.1,
    rootMargin: '0px 0px -100px 0px'
};

var observer = new IntersectionObserver(function(entries) {
    entries.forEach(function(entry) {
        if (entry.isIntersecting) {
            entry.target.style.opacity = '1';
            entry.target.style.transform = 'translateY(0)';
        }
    });
}, observerOptions);

// Apply fade-in animation to sections
window.addEventListener('load', function() {
    generateFeatures();
    
    var sections = document.querySelectorAll('.feature-card, .about-stat, .why-text, .why-image, .about-image, .customer-card');
    sections.forEach(function(section) {
        section.style.opacity = '0';
        section.style.transform = 'translateY(30px)';
        section.style.transition = 'all 0.6s ease';
        observer.observe(section);
    });
});