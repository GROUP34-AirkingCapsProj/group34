// Menu items data
var menuItems = [
    { name: 'Dashboard', active: false },
    { name: 'User Management', active: false },
    { name: 'Branch Management', active: false },
    { name: 'Warehouse Management', active: false },
    { name: 'Item Management', active: false },
    { name: 'Inventory Management', active: false },
    { name: 'Transaction Management', active: true },
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

// Sample transaction data
var transactionsData = [
    { id: 'TXN-1001', date: '2025-01-20', type: 'Sale', itemName: 'AirKing Pro Laptop', quantity: 2, totalAmount: 2500.00, status: 'Completed' },
    { id: 'TXN-1002', date: '2025-01-19', type: 'Purchase', itemName: 'Ergonomic Office Chair', quantity: 10, totalAmount: 1505.00, status: 'Completed' },
    { id: 'TXN-1003', date: '2025-01-18', type: 'Transfer', itemName: 'Air Filter Universal', quantity: 50, totalAmount: 22500.00, status: 'Pending' },
    { id: 'TXN-1004', date: '2025-01-17', type: 'Restock', itemName: 'Compressor 1.5HP', quantity: 5, totalAmount: 42500.00, status: 'Completed' },
    { id: 'TXN-1005', date: '2025-01-16', type: 'Sale', itemName: 'Cooling Coil Copper', quantity: 20, totalAmount: 64000.00, status: 'Cancelled' }
];

var currentPage = 1;
var rowsPerPage = 5;
var filteredTransactions = transactionsData;

// Generate statistics
function generateTransactionStats() {
    var statsContainer = document.getElementById('transactionStats');
    var totalTransactions = transactionsData.length;
    var completedTransactions = transactionsData.filter(function(t) { return t.status === 'Completed'; }).length;
    var pendingTransactions = transactionsData.filter(function(t) { return t.status === 'Pending'; }).length;
    var totalValue = transactionsData.reduce(function(sum, t) { return sum + t.totalAmount; }, 0).toFixed(2);
    
    var html = '';
html += '<div class="stat-card red"><div class="stat-info"><h3>' + totalTransactions + '</h3><p>Total Transactions</p></div></div>';
html += '<div class="stat-card yellow"><div class="stat-info"><h3>' + completedTransactions + '</h3><p>Completed</p></div></div>';
html += '<div class="stat-card red"><div class="stat-info"><h3>' + pendingTransactions + '</h3><p>Pending</p></div></div>';
html += '<div class="stat-card yellow"><div class="stat-info"><h3>₱' + totalValue + '</h3><p>Total Value</p></div></div>';

statsContainer.innerHTML = html;

}

// Generate table
function generateTransactionTable() {
    var tableHead = document.getElementById('tableHead');
    var tableBody = document.getElementById('tableBody');
    
    var headHtml = '<tr>';
    var headers = ['ID', 'Date', 'Type', 'Item Name', 'Quantity', 'Total Amount', 'Status', 'Actions'];
    for (var i = 0; i < headers.length; i++) {
        headHtml += '<th>' + headers[i] + '</th>';
    }
    headHtml += '</tr>';
    tableHead.innerHTML = headHtml;
    
    var start = (currentPage - 1) * rowsPerPage;
    var end = start + rowsPerPage;
    var paginatedTransactions = filteredTransactions.slice(start, end);
    
    var bodyHtml = '';
    for (var i = 0; i < paginatedTransactions.length; i++) {
        var transaction = paginatedTransactions[i];
        var statusClass = '';
        if (transaction.status === 'Completed') statusClass = 'status-active';
        else if (transaction.status === 'Pending') statusClass = 'status-pending';
        else if (transaction.status === 'Cancelled') statusClass = 'status-cancelled';

        bodyHtml += '<tr>';
        bodyHtml += '<td>' + transaction.id + '</td>';
        bodyHtml += '<td>' + transaction.date + '</td>';
        bodyHtml += '<td>' + transaction.type + '</td>';
        bodyHtml += '<td>' + transaction.itemName + '</td>';
        bodyHtml += '<td>' + transaction.quantity + '</td>';
        bodyHtml += '<td>₱' + transaction.totalAmount.toFixed(2) + '</td>';
        bodyHtml += '<td><span class="' + statusClass + '">' + transaction.status + '</span></td>';
        bodyHtml += '<td>';
        bodyHtml += '<div class="table-actions">';
        bodyHtml += '<button class="btn-view" data-id="' + transaction.id + '">View</button>';
        bodyHtml += '<button class="btn-edit" data-id="' + transaction.id + '">Edit</button>';
        bodyHtml += '<button class="btn-delete" data-id="' + transaction.id + '">Delete</button>';
        bodyHtml += '</div>';
        bodyHtml += '</td>';
        bodyHtml += '</tr>';
    }
    
    if (paginatedTransactions.length === 0) {
        bodyHtml = '<tr><td colspan="8" style="text-align: center; color: #999;">No transactions found</td></tr>';
    }
    
    tableBody.innerHTML = bodyHtml;
    
    addTableEventListeners();
    generatePagination();
}

// Add event listeners
function addTableEventListeners() {
    var viewBtns = document.querySelectorAll('.btn-view');
    viewBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            var transaction = transactionsData.find(function(t) { return t.id == id; });
            if (transaction) {
                var info = 'Transaction Details:\n\n';
                info += 'ID: ' + transaction.id + '\n';
                info += 'Date: ' + transaction.date + '\n';
                info += 'Type: ' + transaction.type + '\n';
                info += 'Item: ' + transaction.itemName + '\n';
                info += 'Quantity: ' + transaction.quantity + '\n';
                info += 'Total Amount: ₱' + transaction.totalAmount.toFixed(2) + '\n';
                info += 'Status: ' + transaction.status;
                alert(info);
            }
        });
    });
    
    var editBtns = document.querySelectorAll('.btn-edit');
    editBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            editTransaction(id);
        });
    });
    
    var deleteBtns = document.querySelectorAll('.btn-delete');
    deleteBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            deleteTransaction(id);
        });
    });
}

// Generate pagination
function generatePagination() {
    var pagination = document.getElementById('pagination');
    var totalPages = Math.ceil(filteredTransactions.length / rowsPerPage);
    
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
            generateTransactionTable();
        }
    });
    
    document.getElementById('nextPage').addEventListener('click', function() {
        var totalPages = Math.ceil(filteredTransactions.length / rowsPerPage);
        if (currentPage < totalPages) {
            currentPage++;
            generateTransactionTable();
        }
    });
    
    var pageBtns = pagination.querySelectorAll('button[data-page]');
    pageBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            currentPage = parseInt(this.getAttribute('data-page'));
            generateTransactionTable();
        });
    });
}

// Modal functions
var modal = document.getElementById('transactionModal');
var addTransactionBtn = document.getElementById('addTransactionBtn');
var closeModal = document.getElementById('closeModal');
var cancelBtn = document.getElementById('cancelBtn');
var transactionForm = document.getElementById('transactionForm');

addTransactionBtn.addEventListener('click', function() {
    document.getElementById('modalTitle').textContent = 'Add New Transaction';
    transactionForm.reset();
    // Generate a new transaction ID
    document.getElementById('transactionId').value = 'TXN-' + (transactionsData.length + 1001);
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

// Calculate total amount on the fly
document.getElementById('quantity').addEventListener('input', calculateTotal);
document.getElementById('unitPrice').addEventListener('input', calculateTotal);

function calculateTotal() {
    var quantity = parseFloat(document.getElementById('quantity').value) || 0;
    var unitPrice = parseFloat(document.getElementById('unitPrice').value) || 0;
    var total = quantity * unitPrice;
    document.getElementById('totalAmount').value = total.toFixed(2);
}

// Form submission
transactionForm.addEventListener('submit', function(e) {
    e.preventDefault();
    
    var editingId = transactionForm.getAttribute('data-editing-id');
    
    if (editingId) {
        var index = transactionsData.findIndex(function(t) { return t.id == editingId; });
        if (index !== -1) {
            transactionsData[index] = {
                id: editingId,
                date: document.getElementById('transactionDate').value,
                type: document.getElementById('type').value,
                itemName: document.getElementById('itemName').value,
                quantity: parseInt(document.getElementById('quantity').value),
                totalAmount: parseFloat(document.getElementById('totalAmount').value),
                status: document.getElementById('status').value,
                notes: document.getElementById('notes').value
            };
            alert('Transaction updated successfully!');
        }
        transactionForm.removeAttribute('data-editing-id');
    } else {
        var newTransaction = {
            id: document.getElementById('transactionId').value,
            date: document.getElementById('transactionDate').value,
            type: document.getElementById('type').value,
            itemName: document.getElementById('itemName').value,
            quantity: parseInt(document.getElementById('quantity').value),
            totalAmount: parseFloat(document.getElementById('totalAmount').value),
            status: document.getElementById('status').value,
            notes: document.getElementById('notes').value
        };
        transactionsData.push(newTransaction);
        alert('Transaction added successfully!');
    }
    
    filteredTransactions = transactionsData;
    generateTransactionStats();
    generateTransactionTable();
    modal.style.display = 'none';
});

// Edit transaction
function editTransaction(id) {
    var transaction = transactionsData.find(function(t) { return t.id == id; });
    if (transaction) {
        document.getElementById('modalTitle').textContent = 'Edit Transaction';
        document.getElementById('transactionId').value = transaction.id;
        document.getElementById('transactionDate').value = transaction.date;
        document.getElementById('type').value = transaction.type;
        document.getElementById('itemName').value = transaction.itemName;
        document.getElementById('quantity').value = transaction.quantity;
        document.getElementById('unitPrice').value = (transaction.totalAmount / transaction.quantity).toFixed(2);
        document.getElementById('totalAmount').value = transaction.totalAmount.toFixed(2);
        document.getElementById('status').value = transaction.status;
        document.getElementById('notes').value = transaction.notes || '';
        transactionForm.setAttribute('data-editing-id', id);
        modal.style.display = 'block';
    }
}

// Delete transaction
function deleteTransaction(id) {
    if (confirm('Are you sure you want to delete this transaction?')) {
        transactionsData = transactionsData.filter(function(t) { return t.id != id; });
        filteredTransactions = transactionsData;
        generateTransactionStats();
        generateTransactionTable();
        alert('Transaction deleted successfully!');
    }
}

// Filter functions
document.getElementById('typeFilter').addEventListener('change', applyFilters);
document.getElementById('statusFilter').addEventListener('change', applyFilters);
document.getElementById('dateFromFilter').addEventListener('change', applyFilters);
document.getElementById('dateToFilter').addEventListener('change', applyFilters);

function applyFilters() {
    var typeFilter = document.getElementById('typeFilter').value;
    var statusFilter = document.getElementById('statusFilter').value;
    var dateFromFilter = document.getElementById('dateFromFilter').value;
    var dateToFilter = document.getElementById('dateToFilter').value;
    
    filteredTransactions = transactionsData.filter(function(transaction) {
        var matchType = !typeFilter || transaction.type === typeFilter;
        var matchStatus = !statusFilter || transaction.status === statusFilter;
        var matchDateFrom = !dateFromFilter || transaction.date >= dateFromFilter;
        var matchDateTo = !dateToFilter || transaction.date <= dateToFilter;
        return matchType && matchStatus && matchDateFrom && matchDateTo;
    });
    
    currentPage = 1;
    generateTransactionTable();
}

document.getElementById('resetFilter').addEventListener('click', function() {
    document.getElementById('typeFilter').value = '';
    document.getElementById('statusFilter').value = '';
    document.getElementById('dateFromFilter').value = '';
    document.getElementById('dateToFilter').value = '';
    filteredTransactions = transactionsData;
    currentPage = 1;
    generateTransactionTable();
});

// Search functionality
document.getElementById('searchBtn').addEventListener('click', function() {
    var searchTerm = document.getElementById('searchInput').value.toLowerCase();
    if (searchTerm) {
        filteredTransactions = transactionsData.filter(function(transaction) {
            return transaction.id.toLowerCase().includes(searchTerm) ||
                   transaction.itemName.toLowerCase().includes(searchTerm);
        });
        currentPage = 1;
        generateTransactionTable();
    }
});

document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        document.getElementById('searchBtn').click();
    }
});

// Export and Print
document.getElementById('exportBtn').addEventListener('click', function() {
    alert('Exporting transactions data...');
});

document.getElementById('printBtn').addEventListener('click', function() {
    window.print();
});

// Initialize on page load
window.addEventListener('load', function() {
    generateNav();
    generateHeader();
    generateTransactionStats();
    generateTransactionTable();
    
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
                
                // Navigate based on the text
                switch (text) {
                    case 'Dashboard':
                        window.location.href = '../html/dashboard_air.html';
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
                     case 'Inventory Management':
                        window.location.href = '../html/transaction_management.html';
                        break;
                    case 'Transaction Management':
                        // Already on the page, do nothing
                        break;
                    default:
                        alert('Module "' + text + '" is under development');
                        break;
                }
            });
        });
    }, 100);
});