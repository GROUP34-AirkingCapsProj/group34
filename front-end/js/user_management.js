// Menu items data
var menuItems = [
    { name: 'Dashboard', active: false },
    { name: 'User Management', active: true },
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

// Sample user data
var usersData = [
    { id: 1, firstName: 'Juan', lastName: 'Dela Cruz', email: 'juan@airking.com', username: 'juan.delacruz', role: 'System Admin', branch: 'Main Branch', status: 'Active', phone: '09171234567' },
    { id: 2, firstName: 'Maria', lastName: 'Santos', email: 'maria@airking.com', username: 'maria.santos', role: 'Branch Manager', branch: 'CDO Branch', status: 'Active', phone: '09181234567' },
    { id: 3, firstName: 'Pedro', lastName: 'Reyes', email: 'pedro@airking.com', username: 'pedro.reyes', role: 'Warehouse Personnel', branch: 'Main Branch', status: 'Active', phone: '09191234567' },
    { id: 4, firstName: 'Ana', lastName: 'Garcia', email: 'ana@airking.com', username: 'ana.garcia', role: 'Inventory Analyst', branch: 'Davao Branch', status: 'Active', phone: '09201234567' },
    { id: 5, firstName: 'Jose', lastName: 'Ramos', email: 'jose@airking.com', username: 'jose.ramos', role: 'Auditor', branch: 'Main Branch', status: 'Inactive', phone: '09211234567' }
];

var currentPage = 1;
var rowsPerPage = 5;
var filteredUsers = usersData;

// Generate table
function generateUserTable() {
    var tableHead = document.getElementById('tableHead');
    var tableBody = document.getElementById('tableBody');
    
    // Generate header
    var headHtml = '<tr>';
    var headers = ['ID', 'Name', 'Email', 'Username', 'Role', 'Branch', 'Status', 'Actions'];
    for (var i = 0; i < headers.length; i++) {
        headHtml += '<th>' + headers[i] + '</th>';
    }
    headHtml += '</tr>';
    tableHead.innerHTML = headHtml;
    
    // Calculate pagination
    var start = (currentPage - 1) * rowsPerPage;
    var end = start + rowsPerPage;
    var paginatedUsers = filteredUsers.slice(start, end);
    
    // Generate body
    var bodyHtml = '';
    for (var i = 0; i < paginatedUsers.length; i++) {
        var user = paginatedUsers[i];
        var statusClass = user.status === 'Active' ? 'status-active' : 'status-inactive';
        
        bodyHtml += '<tr>';
        bodyHtml += '<td>' + user.id + '</td>';
        bodyHtml += '<td>' + user.firstName + ' ' + user.lastName + '</td>';
        bodyHtml += '<td>' + user.email + '</td>';
        bodyHtml += '<td>' + user.username + '</td>';
        bodyHtml += '<td>' + user.role + '</td>';
        bodyHtml += '<td>' + user.branch + '</td>';
        bodyHtml += '<td><span class="' + statusClass + '">' + user.status + '</span></td>';
        bodyHtml += '<td>';
        bodyHtml += '<div class="table-actions">'; // <-- ADDED WRAPPER FOR BUTTON SPACING
        bodyHtml += '<button class="btn-view" data-id="' + user.id + '">View</button>';
        bodyHtml += '<button class="btn-edit" data-id="' + user.id + '">Edit</button>';
        bodyHtml += '<button class="btn-delete" data-id="' + user.id + '">Delete</button>';
        bodyHtml += '</div>'; // <-- CLOSED WRAPPER
        bodyHtml += '</td>';
        bodyHtml += '</tr>';
    }
    
    if (paginatedUsers.length === 0) {
        bodyHtml = '<tr><td colspan="8" style="text-align: center; color: #999;">No users found</td></tr>';
    }
    
    tableBody.innerHTML = bodyHtml;
    
    addTableEventListeners();
    generatePagination();
}

// Add event listeners to table buttons
function addTableEventListeners() {
    // View buttons
    var viewBtns = document.querySelectorAll('.btn-view');
    viewBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            var user = usersData.find(function(u) { return u.id == id; });
            if (user) {
                var info = 'User Details:\n\n';
                info += 'Name: ' + user.firstName + ' ' + user.lastName + '\n';
                info += 'Email: ' + user.email + '\n';
                info += 'Username: ' + user.username + '\n';
                info += 'Role: ' + user.role + '\n';
                info += 'Branch: ' + user.branch + '\n';
                info += 'Status: ' + user.status + '\n';
                info += 'Phone: ' + user.phone;
                alert(info);
            }
        });
    });
    
    // Edit buttons
    var editBtns = document.querySelectorAll('.btn-edit');
    editBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            editUser(id);
        });
    });
    
    // Delete buttons
    var deleteBtns = document.querySelectorAll('.btn-delete');
    deleteBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            deleteUser(id);
        });
    });
}

// Generate pagination
function generatePagination() {
    var pagination = document.getElementById('pagination');
    var totalPages = Math.ceil(filteredUsers.length / rowsPerPage);
    
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
            generateUserTable();
        }
    });
    
    document.getElementById('nextPage').addEventListener('click', function() {
        var totalPages = Math.ceil(filteredUsers.length / rowsPerPage);
        if (currentPage < totalPages) {
            currentPage++;
            generateUserTable();
        }
    });
    
    var pageBtns = pagination.querySelectorAll('button[data-page]');
    pageBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            currentPage = parseInt(this.getAttribute('data-page'));
            generateUserTable();
        });
    });
}

// Modal functions
var modal = document.getElementById('userModal');
var addUserBtn = document.getElementById('addUserBtn');
var closeModal = document.getElementById('closeModal');
var cancelBtn = document.getElementById('cancelBtn');
var userForm = document.getElementById('userForm');

addUserBtn.addEventListener('click', function() {
    document.getElementById('modalTitle').textContent = 'Add New User';
    userForm.reset();
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
userForm.addEventListener('submit', function(e) {
    e.preventDefault();
    
    var newUser = {
        id: usersData.length + 1,
        firstName: document.getElementById('firstName').value,
        lastName: document.getElementById('lastName').value,
        email: document.getElementById('email').value,
        username: document.getElementById('username').value,
        role: document.getElementById('role').value,
        branch: document.getElementById('branch').value,
        status: document.getElementById('status').value,
        phone: document.getElementById('phone').value
    };
    
    usersData.push(newUser);
    filteredUsers = usersData;
    generateUserTable();
    modal.style.display = 'none';
    alert('User added successfully!');
});

// Edit user
function editUser(id) {
    var user = usersData.find(function(u) { return u.id == id; });
    if (user) {
        document.getElementById('modalTitle').textContent = 'Edit User';
        document.getElementById('firstName').value = user.firstName;
        document.getElementById('lastName').value = user.lastName;
        document.getElementById('email').value = user.email;
        document.getElementById('username').value = user.username;
        document.getElementById('role').value = user.role;
        document.getElementById('branch').value = user.branch;
        document.getElementById('status').value = user.status;
        document.getElementById('phone').value = user.phone;
        modal.style.display = 'block';
    }
}

// Delete user
function deleteUser(id) {
    if (confirm('Are you sure you want to delete this user?')) {
        usersData = usersData.filter(function(u) { return u.id != id; });
        filteredUsers = usersData;
        generateUserTable();
        alert('User deleted successfully!');
    }
}

// Filter functions
document.getElementById('roleFilter').addEventListener('change', applyFilters);
document.getElementById('statusFilter').addEventListener('change', applyFilters);
document.getElementById('branchFilter').addEventListener('change', applyFilters);

function applyFilters() {
    var roleFilter = document.getElementById('roleFilter').value;
    var statusFilter = document.getElementById('statusFilter').value;
    var branchFilter = document.getElementById('branchFilter').value;
    
    filteredUsers = usersData.filter(function(user) {
        var matchRole = !roleFilter || user.role === roleFilter;
        var matchStatus = !statusFilter || user.status === statusFilter;
        var matchBranch = !branchFilter || user.branch === branchFilter;
        return matchRole && matchStatus && matchBranch;
    });
    
    currentPage = 1;
    generateUserTable();
}

document.getElementById('resetFilter').addEventListener('click', function() {
    document.getElementById('roleFilter').value = '';
    document.getElementById('statusFilter').value = '';
    document.getElementById('branchFilter').value = '';
    filteredUsers = usersData;
    currentPage = 1;
    generateUserTable();
});

// Search functionality
document.getElementById('searchBtn').addEventListener('click', function() {
    var searchTerm = document.getElementById('searchInput').value.toLowerCase();
    if (searchTerm) {
        filteredUsers = usersData.filter(function(user) {
            return user.firstName.toLowerCase().includes(searchTerm) ||
                   user.lastName.toLowerCase().includes(searchTerm) ||
                   user.email.toLowerCase().includes(searchTerm) ||
                   user.username.toLowerCase().includes(searchTerm);
        });
        currentPage = 1;
        generateUserTable();
    }
});

document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        document.getElementById('searchBtn').click();
    }
});

// Export and Print
document.getElementById('exportBtn').addEventListener('click', function() {
    alert('Exporting users data...');
});

document.getElementById('printBtn').addEventListener('click', function() {
    window.print();
});

// Initialize on page load
window.addEventListener('load', function() {
    generateNav();
    generateHeader();
    generateUserTable();
    
    // --- MAJOR FIX IS HERE ---
    // Setup navigation event listeners after a short delay to ensure DOM is ready
    setTimeout(function() {
        var navItems = document.querySelectorAll('.nav-item');
        navItems.forEach(function(item) {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                
                // --- THE FIX ---
                // Get the text from the second span, which contains the page name
                var text = this.querySelector('span:last-child').textContent.trim();
                // --- END OF FIX ---
                
                // Set active state for the clicked item
                navItems.forEach(function(nav) { nav.classList.remove('active'); });
                this.classList.add('active');
                
                // --- FIX: Corrected and expanded navigation ---
                switch (text) {
                    case 'Dashboard':
                        window.location.href = '../html/dashboard_air.html';
                        break;
                    case 'User Management':
                        // Already on the page, do nothing
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
                        alert('Module "' + text + '" is under development');
                        break;
                }
            });
        });
    }, 100);
});