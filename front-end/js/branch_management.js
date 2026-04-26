// Menu items data
var menuItems = [
    { name: 'Dashboard', active: false },
    { name: 'User Management', active: false },
    { name: 'Branch Management', active: true },
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
    
    
    document.getElementById('notifBtn').addEventListener('click', function() {
        alert('You have 3 new notifications');
    });
    
    document.getElementById('settingsBtn').addEventListener('click', function() {
        alert('Opening settings...');
    });
}

// Sample branch data
var branchesData = [
    { id: 1, name: 'Main Branch', code: 'MB-001', region: 'Mindanao', city: 'Cagayan de Oro', address: 'CM Recto Ave, Cagayan de Oro City', contact: '0917-123-4567', email: 'main@airking.com', manager: 'Juan Dela Cruz', openingDate: '2015-01-15', status: 'Active' },
    { id: 2, name: 'CDO Branch', code: 'CDO-001', region: 'Mindanao', city: 'Cagayan de Oro', address: 'Divisoria, Cagayan de Oro City', contact: '0918-123-4567', email: 'cdo@airking.com', manager: 'Maria Santos', openingDate: '2016-03-20', status: 'Active' },
    { id: 3, name: 'Davao Branch', code: 'DVO-001', region: 'Mindanao', city: 'Davao', address: 'Magallanes St, Davao City', contact: '0919-123-4567', email: 'davao@airking.com', manager: 'Pedro Reyes', openingDate: '2017-06-10', status: 'Active' },
    { id: 4, name: 'Tagum Branch', code: 'TGM-001', region: 'Mindanao', city: 'Tagum', address: 'Rizal St, Tagum City', contact: '0920-123-4567', email: 'tagum@airking.com', manager: 'Ana Garcia', openingDate: '2018-08-05', status: 'Active' },
    { id: 5, name: 'Butuan Branch', code: 'BUT-001', region: 'Mindanao', city: 'Butuan', address: 'JC Aquino Ave, Butuan City', contact: '0921-123-4567', email: 'butuan@airking.com', manager: 'Jose Ramos', openingDate: '2019-10-15', status: 'Active' },
    { id: 6, name: 'Pagadian Branch', code: 'PAG-001', region: 'Mindanao', city: 'Pagadian', address: 'Rizal Ave, Pagadian City', contact: '0922-123-4567', email: 'pagadian@airking.com', manager: 'Linda Cruz', openingDate: '2020-02-20', status: 'Inactive' }
];

var currentPage = 1;
var rowsPerPage = 5;
var filteredBranches = branchesData;


function generateStats() {
    var statsContainer = document.getElementById('branchStats');
    var totalBranches = branchesData.length;
    var activeBranches = branchesData.filter(function(b) { return b.status === 'Active'; }).length;
    var inactiveBranches = branchesData.filter(function(b) { return b.status === 'Inactive'; }).length;
    
    var html = '';
    html += '<div class="stat-card red"><div class="stat-info"><h3>' + totalBranches + '</h3><p>Total Branches</p></div></div>';
    html += '<div class="stat-card yellow"><div class="stat-info"><h3>' + activeBranches + '</h3><p>Active Branches</p></div></div>';
    html += '<div class="stat-card red"><div class="stat-info"><h3>' + inactiveBranches + '</h3><p>Inactive Branches</p></div></div>';
    html += '<div class="stat-card yellow"><div class="stat-info"><h3>3</h3><p>Regions</p></div></div>';
    
    statsContainer.innerHTML = html;
}

// Generate table
function generateBranchTable() {
    var tableHead = document.getElementById('tableHead');
    var tableBody = document.getElementById('tableBody');
    
    // Generate header
    var headHtml = '<tr>';
    var headers = ['ID', 'Branch Name', 'Code', 'City', 'Contact', 'Manager', 'Status', 'Actions'];
    for (var i = 0; i < headers.length; i++) {
        headHtml += '<th>' + headers[i] + '</th>';
    }
    headHtml += '</tr>';
    tableHead.innerHTML = headHtml;
    
    // Calculate pagination
    var start = (currentPage - 1) * rowsPerPage;
    var end = start + rowsPerPage;
    var paginatedBranches = filteredBranches.slice(start, end);
    
    // Generate body
    var bodyHtml = '';
    for (var i = 0; i < paginatedBranches.length; i++) {
        var branch = paginatedBranches[i];
        var statusClass = branch.status === 'Active' ? 'status-active' : 'status-inactive';
        
        bodyHtml += '<tr>';
        bodyHtml += '<td>' + branch.id + '</td>';
        bodyHtml += '<td>' + branch.name + '</td>';
        bodyHtml += '<td>' + branch.code + '</td>';
        bodyHtml += '<td>' + branch.city + '</td>';
        bodyHtml += '<td>' + branch.contact + '</td>';
        bodyHtml += '<td>' + branch.manager + '</td>';
        bodyHtml += '<td><span class="' + statusClass + '">' + branch.status + '</span></td>';
        bodyHtml += '<td>';
        bodyHtml += '<div class="table-actions">';
        bodyHtml += '<button class="btn-view" data-id="' + branch.id + '">View</button>';
        bodyHtml += '<button class="btn-edit" data-id="' + branch.id + '">Edit</button>';
        bodyHtml += '<button class="btn-delete" data-id="' + branch.id + '">Delete</button>';
        bodyHtml += '</div>';
        bodyHtml += '</td>';
        bodyHtml += '</tr>';
    }
    
    if (paginatedBranches.length === 0) {
        bodyHtml = '<tr><td colspan="8" style="text-align: center; color: #999;">No branches found</td></tr>';
    }
    
    tableBody.innerHTML = bodyHtml;
    
    addTableEventListeners();
    generatePagination();
}

// Add event listeners to table buttons
function addTableEventListeners() {
    var viewBtns = document.querySelectorAll('.btn-view');
    viewBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            var branch = branchesData.find(function(b) { return b.id == id; });
            if (branch) {
                var info = 'Branch Details:\n\n';
                info += 'Name: ' + branch.name + '\n';
                info += 'Code: ' + branch.code + '\n';
                info += 'Region: ' + branch.region + '\n';
                info += 'City: ' + branch.city + '\n';
                info += 'Address: ' + branch.address + '\n';
                info += 'Contact: ' + branch.contact + '\n';
                info += 'Email: ' + branch.email + '\n';
                info += 'Manager: ' + branch.manager + '\n';
                info += 'Opening Date: ' + branch.openingDate + '\n';
                info += 'Status: ' + branch.status;
                alert(info);
            }
        });
    });
    
    var editBtns = document.querySelectorAll('.btn-edit');
    editBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            editBranch(id);
        });
    });
    
    var deleteBtns = document.querySelectorAll('.btn-delete');
    deleteBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            deleteBranch(id);
        });
    });
}

// Generate pagination
function generatePagination() {
    var pagination = document.getElementById('pagination');
    var totalPages = Math.ceil(filteredBranches.length / rowsPerPage);
    
    var html = '';
    html += '<button ' + (currentPage === 1 ? 'disabled' : '') + ' id="prevPage">Previous</button>';
    
    for (var i = 1; i <= totalPages; i++) {
        var activeClass = i === currentPage ? 'active' : '';
        html += '<button class="' + activeClass + '" data-page="' + i + '">' + i + '</button>';
    }
    
    html += '<button ' + (currentPage === totalPages ? 'disabled' : '') + ' id="nextPage">Next</button>';
    
    pagination.innerHTML = html;
    
    document.getElementById('prevPage').addEventListener('click', function() {
        if (currentPage > 1) {
            currentPage--;
            generateBranchTable();
        }
    });
    
    document.getElementById('nextPage').addEventListener('click', function() {
        var totalPages = Math.ceil(filteredBranches.length / rowsPerPage);
        if (currentPage < totalPages) {
            currentPage++;
            generateBranchTable();
        }
    });
    
    var pageBtns = pagination.querySelectorAll('button[data-page]');
    pageBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            currentPage = parseInt(this.getAttribute('data-page'));
            generateBranchTable();
        });
    });
}

// Modal functions
var modal = document.getElementById('branchModal');
var addBranchBtn = document.getElementById('addBranchBtn');
var closeModal = document.getElementById('closeModal');
var cancelBtn = document.getElementById('cancelBtn');
var branchForm = document.getElementById('branchForm');

addBranchBtn.addEventListener('click', function() {
    document.getElementById('modalTitle').textContent = 'Add New Branch';
    branchForm.reset();
    modal.style.display = 'block';
});

closeModal.addEventListener('click', function() {
    modal.style.display = 'none';
});

cancelBtn.addEventListener('click', function() {
    modal.style.display = 'none';
});

window.addEventListener('click', function(e) {
    if (e.target == modal) {
        modal.style.display = 'none';
    }
});

// Form submission
branchForm.addEventListener('submit', function(e) {
    e.preventDefault();
    
    var newBranch = {
        id: branchesData.length + 1,
        name: document.getElementById('branchName').value,
        code: document.getElementById('branchCode').value,
        region: document.getElementById('region').value,
        city: document.getElementById('city').value,
        address: document.getElementById('address').value,
        contact: document.getElementById('contactNumber').value,
        email: document.getElementById('email').value,
        manager: document.getElementById('managerName').value,
        openingDate: document.getElementById('openingDate').value,
        status: document.getElementById('status').value
    };
    
    branchesData.push(newBranch);
    filteredBranches = branchesData;
    generateStats();
    generateBranchTable();
    modal.style.display = 'none';
    alert('Branch added successfully!');
});

// Edit branch
function editBranch(id) {
    var branch = branchesData.find(function(b) { return b.id == id; });
    if (branch) {
        document.getElementById('modalTitle').textContent = 'Edit Branch';
        document.getElementById('branchName').value = branch.name;
        document.getElementById('branchCode').value = branch.code;
        document.getElementById('region').value = branch.region;
        document.getElementById('city').value = branch.city;
        document.getElementById('address').value = branch.address;
        document.getElementById('contactNumber').value = branch.contact;
        document.getElementById('email').value = branch.email;
        document.getElementById('managerName').value = branch.manager;
        document.getElementById('openingDate').value = branch.openingDate;
        document.getElementById('status').value = branch.status;
        modal.style.display = 'block';
    }
}

// Delete branch
function deleteBranch(id) {
    if (confirm('Are you sure you want to delete this branch?')) {
        branchesData = branchesData.filter(function(b) { return b.id != id; });
        filteredBranches = branchesData;
        generateStats();
        generateBranchTable();
        alert('Branch deleted successfully!');
    }
}

// Filter functions
document.getElementById('statusFilter').addEventListener('change', applyFilters);
document.getElementById('regionFilter').addEventListener('change', applyFilters);

function applyFilters() {
    var statusFilter = document.getElementById('statusFilter').value;
    var regionFilter = document.getElementById('regionFilter').value;
    
    filteredBranches = branchesData.filter(function(branch) {
        var matchStatus = !statusFilter || branch.status === statusFilter;
        var matchRegion = !regionFilter || branch.region === regionFilter;
        return matchStatus && matchRegion;
    });
    
    currentPage = 1;
    generateBranchTable();
}

document.getElementById('resetFilter').addEventListener('click', function() {
    document.getElementById('statusFilter').value = '';
    document.getElementById('regionFilter').value = '';
    filteredBranches = branchesData;
    currentPage = 1;
    generateBranchTable();
});

// Search functionality
document.getElementById('searchBtn').addEventListener('click', function() {
    var searchTerm = document.getElementById('searchInput').value.toLowerCase();
    if (searchTerm) {
        filteredBranches = branchesData.filter(function(branch) {
            return branch.name.toLowerCase().includes(searchTerm) ||
                   branch.code.toLowerCase().includes(searchTerm) ||
                   branch.city.toLowerCase().includes(searchTerm);
        });
        currentPage = 1;
        generateBranchTable();
    }
});

document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        document.getElementById('searchBtn').click();
    }
});

// Export and Print
document.getElementById('exportBtn').addEventListener('click', function() {
    alert('Exporting branches data...');
});

document.getElementById('printBtn').addEventListener('click', function() {
    window.print();
});

// Initialize on page load
window.addEventListener('load', function() {
    generateNav();
    generateHeader();
    generateStats();
    generateBranchTable();
    
    // Setup navigation
    setTimeout(function() {
        var navItems = document.querySelectorAll('.nav-item');
        navItems.forEach(function(item) {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Get the text from the second span, which contains the page name
                var text = this.querySelector('span:last-child').textContent.trim();
                
                // Set active state for the clicked item
                navItems.forEach(function(nav) { nav.classList.remove('active'); });
                this.classList.add('active');
                
                // Navigate based on the text
                switch (text) {
                    case 'Dashboard':
                        window.location.href = '../html/dashboard_air.html';
                        break;
                    case 'User Management':
                        window.location.href = '../html/user_management.html';
                        break;
                    case 'Branch Management':
                        
                        break;
                    case 'Warehouse Management':
                        window.location.href = '../html/warehouse_management.html';
                        break;
                    case 'Item Management':
                        window.location.href = '../../frontend/html/item_management.html';
                        break;
                    case 'Inventory Management':
                        window.location.href = '../html/inventory_management.html';
                        break;
                    case 'Transaction Management':
                        window.location.href = '../html/transaction_management.html';
                        break;
                    default:
                        alert('Module "' + text + '" is under development');
                        break;
                }
            });
        });
    }, 100);
});