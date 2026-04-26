// Menu items data
var menuItems = [
    { name: 'Dashboard', active: true },
    { name: 'User Management', active: false },
    { name: 'Branch Management', active: false },
    { name: 'Warehouse Management', active: false },
    { name: 'Item Management', active: false },
    { name: 'Inventory Management', active: false },
    { name: 'Transaction Management', active: false },
    { name: 'Sales Management', active: false },
    { name: 'Purchase Management', active: false },
    { name: 'Restock Management', active: false },
    { name: 'Transfer Management', active: false },
    { name: 'Warranty & Repairs', active: false },
    { name: 'Returns Management', active: false },
    { name: 'Document Management', active: false },
    { name: 'Reporting', active: false },
    { name: 'Audit & Compliance', active: false },
    { name: 'Settings', active: false }
];

// Generate navigation menu
function generateNav() {
    var navMenu = document.getElementById('navMenu');
    var html = '';
    
    for (var i = 0; i < menuItems.length; i++) {
        var activeClass = menuItems[i].active ? 'active' : '';
        html += '<a href="#" class="nav-item ' + activeClass + '">';
        html += '<span class="icon">▸</span>';
        html += '<span>' + menuItems[i].name + '</span>';
        html += '</a>';
    }
    
    navMenu.innerHTML = html;
}

// Generate header right section
function generateHeader() {
    var headerRight = document.getElementById('headerRight');
    var html = '';
    
    html += '<span class="role-badge">System Admin</span>';
    html += '<button class="icon-btn" id="notifBtn">Bell</button>';
    html += '<button class="icon-btn" id="settingsBtn">Settings</button>';
    html += '<div class="user-profile">';
    html += '<img src="../../images/air.png" alt="User">';
    html += '<span>Admin</span>';
    html += '</div>';
    html += '<a href="../../frontend/html/airking_login.html" class="logout-btn">Logout</a>';
    
    headerRight.innerHTML = html;
    
    // Add event listeners
    document.getElementById('notifBtn').addEventListener('click', function() {
        alert('You have 3 new notifications');
    });
    
    document.getElementById('settingsBtn').addEventListener('click', function() {
        alert('Opening settings...');
    });
}

// Generate welcome section
function generateWelcome() {
    var welcomeSection = document.getElementById('welcomeSection');
    var html = '';
    
    html += '<h1>Welcome to AirKing Inventory System</h1>';
    html += '<p>Digital Material Movement Ledger, Barcode-Driven Audit Trail, and Smart Restocking Workflow</p>';
    
    welcomeSection.innerHTML = html;
}

// Statistics data sample
var statsData = [
    { value: '1,250', label: 'Total Items', color: 'red' },
    { value: '385', label: 'New Orders', color: 'yellow' },
    { value: '₱850K', label: 'Total Sales', color: 'red' },
    { value: '45', label: 'Low Stock Items', color: 'yellow' }
];

// Generate statistics cards 
function generateStats() {
    var statsContainer = document.getElementById('statsContainer');
    var html = '';
    
    for (var i = 0; i < statsData.length; i++) {
        html += '<div class="stat-card ' + statsData[i].color + '">';
        html += '<div class="stat-info">';
        html += '<h3>' + statsData[i].value + '</h3>';
        html += '<p>' + statsData[i].label + '</p>';
        html += '</div>';
        html += '</div>';
    }
    
    statsContainer.innerHTML = html;
}

// Generate charts section
function generateCharts() {
    var chartsSection = document.getElementById('chartsSection');
    var html = '';
    
    // Chart 1
    html += '<div class="chart-card">';
    html += '<h3>Inventory Movement Overview</h3>';
    html += '<div class="chart-placeholder">';
    var heights = [60, 80, 45, 90, 70, 55];
    for (var i = 0; i < heights.length; i++) {
        html += '<div class="chart-bar" style="height: ' + heights[i] + '%"></div>';
    }
    html += '</div>';
    html += '</div>';
    
    // Chart 2
    html += '<div class="chart-card">';
    html += '<h3>Stock Levels by Category</h3>';
    html += '<div class="category-stats">';
    
    var categories = [
        { name: 'Filters', percent: 85 },
        { name: 'Compressors', percent: 60 },
        { name: 'Coils', percent: 40 },
        { name: 'Motors', percent: 75 }
    ];
    
    for (var i = 0; i < categories.length; i++) {
        html += '<div class="category-item">';
        html += '<span>' + categories[i].name + '</span>';
        html += '<div class="progress-bar">';
        html += '<div class="progress" style="width: ' + categories[i].percent + '%"></div>';
        html += '</div>';
        html += '<span>' + categories[i].percent + '%</span>';
        html += '</div>';
    }
    
    html += '</div>';
    html += '</div>';
    
    chartsSection.innerHTML = html;
}

// Table data ni nga part
var tableData = [
    { id: '#001', name: 'Air Filter Model X', category: 'Filters', quantity: 50, date: '2025-01-15', status: 'Completed', statusClass: 'success' },
    { id: '#002', name: 'Compressor Unit A', category: 'Compressors', quantity: 25, date: '2025-01-14', status: 'Pending', statusClass: 'warning' },
    { id: '#003', name: 'Cooling Coil B', category: 'Coils', quantity: 100, date: '2025-01-13', status: 'Completed', statusClass: 'success' },
    { id: '#004', name: 'Fan Motor C', category: 'Motors', quantity: 30, date: '2025-01-12', status: 'Completed', statusClass: 'success' },
    { id: '#005', name: 'Thermostat D', category: 'Controls', quantity: 75, date: '2025-01-11', status: 'Cancelled', statusClass: 'danger' }
];

// maka generate syag table
function generateTable() {
    var tableHead = document.getElementById('tableHead');
    var tableBody = document.getElementById('tableBody');
    
    // Generate header
    var headHtml = '<tr>';
    var headers = ['ID', 'Item Name', 'Category', 'Quantity', 'Date', 'Status', 'Action'];
    for (var i = 0; i < headers.length; i++) {
        headHtml += '<th>' + headers[i] + '</th>';
    }
    headHtml += '</tr>';
    tableHead.innerHTML = headHtml;
    
    // Generate body
    var bodyHtml = '';
    for (var i = 0; i < tableData.length; i++) {
        bodyHtml += '<tr>';
        bodyHtml += '<td>' + tableData[i].id + '</td>';
        bodyHtml += '<td>' + tableData[i].name + '</td>';
        bodyHtml += '<td>' + tableData[i].category + '</td>';
        bodyHtml += '<td>' + tableData[i].quantity + '</td>';
        bodyHtml += '<td>' + tableData[i].date + '</td>';
        bodyHtml += '<td><span class="badge ' + tableData[i].statusClass + '">' + tableData[i].status + '</span></td>';
        bodyHtml += '<td><button class="action-btn" data-id="' + tableData[i].id + '">View</button></td>';
        bodyHtml += '</tr>';
    }
    tableBody.innerHTML = bodyHtml;
    
    // Add click events to action buttons
    var actionBtns = document.querySelectorAll('.action-btn');
    actionBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            alert('Viewing details for transaction: ' + id);
        });
    });
}

// Search functionality
function setupSearch() {
    var searchBtn = document.getElementById('searchBtn');
    var searchInput = document.getElementById('searchInput');
    
    searchBtn.addEventListener('click', function() {
        var searchTerm = searchInput.value;
        if (searchTerm) {
            alert('Searching for: ' + searchTerm);
        } else {
            alert('Please enter a search term');
        }
    });
    
    searchInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            searchBtn.click();
        }
    });
}

// Export functionality
function setupExport() {
    var exportBtn = document.getElementById('exportBtn');
    exportBtn.addEventListener('click', function() {
        alert('Exporting data... This feature will be implemented soon.');
    });
}

// Initialize everything
function init() {
    generateNav();
    generateHeader();
    generateWelcome();
    generateStats();
    generateCharts();
    generateTable();
    setupSearch();
    setupExport();

    // Setup navigation event listeners after a short delay to ensure DOM is ready
    setTimeout(function() {
        var navLinks = document.querySelectorAll('.nav-item');
        navLinks.forEach(function(item) {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Get the text from the second span, which contains the page name
                var text = this.querySelector('span:last-child').textContent.trim();
                
                // Set active state for the clicked item
                navLinks.forEach(function(nav) { nav.classList.remove('active'); });
                this.classList.add('active');
                
                // Navigate based on the text
                switch (text) {
                    case 'Dashboard':
                        
                        break;
                    case 'User Management':
                        window.location.href = '../html/user_management.html';
                        break;
                    case 'Branch Management':
                        window.location.href = '../html/branch_management.html';
                        break;
                    case 'Warehouse Management':
                        window.location.href = '../html/warehouse_management.html';
                        break;
                    case 'Item Management':
                        window.location.href = '../html/item_management.html';
                        break;
                    case 'Inventory Management':
                        window.location.href = '../html/inventory_management.html';
                        break;
                    case 'Transaction Management':
                        window.location.href = '../html/transaction_management.html';
                        break;
                    default:
                        // For other menu items not yet created
                        alert('Module "' + text + '" is under development');
                        break;
                }
            });
        });
    }, 100);
}

// Run when page loads
window.addEventListener('load', init);