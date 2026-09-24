document.addEventListener('DOMContentLoaded', function() {
    // Particle System
    const canvas = document.getElementById('particle-container');
    const ctx = canvas ? canvas.getContext('2d') : null;
    let particles = [];

    function initParticles() {
        if(!canvas || !ctx) return;
        canvas.width = window.innerWidth;
        canvas.height = document.body.scrollHeight;
        particles = [];
        let numParticles = (canvas.width * canvas.height) / 15000;
        for (let i = 0; i < numParticles; i++) {
            let size = (Math.random() * 2) + 1;
            let x = Math.random() * canvas.width;
            let y = Math.random() * canvas.height;
            let dirX = (Math.random() * 0.4) - 0.2;
            let dirY = (Math.random() * 0.4) - 0.2;
            let color = document.documentElement.classList.contains('dark') ? 'rgba(255,255,255,0.6)' : 'rgba(0,0,0,0.6)';
            particles.push({ x, y, dirX, dirY, size, color });
        }
    }

    function animateParticles() {
        if (!ctx || !canvas) return;
        requestAnimationFrame(animateParticles);
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        for (let p of particles) {
            if (p.x < 0 || p.x > canvas.width) p.dirX *= -1;
            if (p.y < 0 || p.y > canvas.height) p.dirY *= -1;
            p.x += p.dirX;
            p.y += p.dirY;
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
            ctx.fillStyle = p.color;
            ctx.fill();
        }
    }

    // Theme toggle
    const themeToggle = document.getElementById('themeToggle');
    const themeIcon = document.getElementById('themeIcon');
    const html = document.documentElement;

    function updateTheme(isInitial = false) {
        const isDark = html.classList.contains('dark');
        if(themeIcon) {
            themeIcon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        }
        if (!isInitial) {
            // Re-init particles with new colors
            initParticles();
        }
    }

    if(themeToggle) {
        themeToggle.addEventListener('click', () => {
            html.classList.toggle('dark');
            localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light');
            updateTheme();
        });
    }

    // Initial Load
    window.addEventListener('resize', initParticles);
    if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        html.classList.add('dark');
    } else {
        html.classList.remove('dark');
    }
    updateTheme(true);
    initParticles();
    animateParticles();

    // Filter functionality
    const filterBtns = document.querySelectorAll('.filter-btn');
    const searchInput = document.getElementById('searchClient');
    const searchTransactionIdInput = document.getElementById('searchTransactionId');
    const dateFromInput = document.getElementById('dateFrom');
    const dateToInput = document.getElementById('dateTo');
    const applyDateBtn = document.getElementById('applyDateFilter');
    const tableBody = document.getElementById('transactionTableBody');
    const showingCount = document.getElementById('showingCount');
    const totalCount = document.getElementById('totalCount');
    const paginationControls = document.querySelector('.pagination-controls');

    let currentFilter = 'all';
    let currentSearch = '';
    let currentTransactionIdSearch = '';
    let dateFrom = '';
    let dateTo = '';
    let currentPage = 1;

    // Hierarchy filters
    const zoneFilter = document.getElementById('zoneFilter');
    const areaFilter = document.getElementById('areaFilter');
    const branchFilter = document.getElementById('branchFilter');
    const coFilter = document.getElementById('coFilter');

    let currentZone = '';
    let currentArea = '';
    let currentBranch = '';
    let currentCO = '';

    // Data from PHP (assuming these are globally available or passed via a data attribute)
    const allHierarchy = <?php echo json_encode($hierarchy); ?>;
    const allCreditOfficers = <?php echo json_encode($credit_officers); ?>;

    function fetchTransactions() {
        const params = new URLSearchParams({
            ajax: true,
            filter: currentFilter,
            search: currentSearch,
            transaction_id: currentTransactionIdSearch,
            date_from: dateFrom,
            date_to: dateTo,
            page: currentPage,
            zone: currentZone,
            area: currentArea,
            branch: currentBranch,
            co: currentCO
        });

        fetch(`transaction_manager.php?${params}`)
            .then(response => response.json())
            .then(data => {
                renderTable(data.transactions);
                renderPagination(data);
            });
    }

    function renderTable(transactions) {
        tableBody.innerHTML = '';
        if (transactions.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="10" style="text-align: center;">No transactions found.</td></tr>';
            return;
        }

        transactions.forEach(transaction => {
            const row = document.createElement('tr');
            row.className = 'transaction-row';

            const typeClasses = {
                'disbursement': 'badge-green',
                'loan_collection': 'badge-blue',
                'savings_deposit': 'badge-green',
                'savings_withdrawal': 'badge-red',
                'savings_return': 'badge-red'
            };
            let typeClass = typeClasses[transaction.transaction_type] || 'badge-gray';
            if (transaction.recorded_by_bm_username && transaction.recorded_by_bm_username !== '') {
                typeClass = 'badge-purple';
            }

            const amount = Number(transaction.principal_amount || transaction.amount_collected || transaction.amount || 0);
            const totalPayable = transaction.total_payable ? `₦${Number(transaction.total_payable).toLocaleString()}` : '-';
            const installmentAmount = transaction.installment_amount ? `₦${Number(transaction.installment_amount).toLocaleString()}` : '-';
            const remainingBalance = transaction.remaining_balance ? `₦${Number(transaction.remaining_balance).toLocaleString()}` : '-';
            const remainingBalanceColor = transaction.remaining_balance > 0 ? '#dc2626' : '#16a34a';

            row.innerHTML = `
                <td>${new Date(transaction.date).toLocaleString()}</td>
                <td><button class="client-btn" data-client-name="${transaction.client}" data-color="${crc32(transaction.client) % 5}">${transaction.client}</button></td>
                <td>${transaction.branch_name || 'N/A'}</td>
                <td>
                    <span class="badge ${typeClass}">
                        ${transaction.transaction_type.replace('_', ' ').split(' ').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ')}
                        ${transaction.recorded_by_bm_username && transaction.recorded_by_bm_username !== '' ? `(${transaction.recorded_by_bm_username})` : ''}
                    </span>
                </td>
                <td><span style="font-weight: 500;">₦${amount.toLocaleString()}</span></td>
                <td><span style="font-weight: 500; color: #059669;">${totalPayable}</span></td>
                <td><span style="font-weight: 500; color: #7c3aed;">${installmentAmount}</span></td>
                <td><span style="font-weight: 500; color: ${remainingBalanceColor};">${remainingBalance}</span></td>
                <td>${transaction.officer || 'N/A'}</td>
                <td>
                    <div style="display: flex; gap: 0.5rem;">
                        <button onclick='editTransaction(${JSON.stringify(transaction)})' class="edit-button" title="Edit Transaction"><i class="fas fa-edit"></i></button>
                        <button onclick='deleteTransaction(${JSON.stringify(transaction)})' class="delete-button" title="Delete Transaction"><i class="fas fa-trash"></i></button>
                    </div>
                </td>
            `;
            tableBody.appendChild(row);
        });
    }

    function renderPagination(data) {
        const { total_rows, page, per_page } = data;
        const pageCount = Math.ceil(total_rows / per_page);

        showingCount.textContent = data.transactions.length;
        totalCount.textContent = total_rows;

        paginationControls.innerHTML = '';
        if (pageCount <= 1) return;

        const createButton = (text, pageNum, isDisabled) => {
            const button = document.createElement('button');
            button.textContent = text;
            button.disabled = isDisabled;
            button.addEventListener('click', () => {
                currentPage = pageNum;
                fetchTransactions();
            });
            return button;
        };

        paginationControls.appendChild(createButton('First', 1, page === 1));
        paginationControls.appendChild(createButton('Previous', page - 1, page === 1));

        const pageInfo = document.createElement('span');
        pageInfo.textContent = `Page ${page} of ${pageCount}`;
        paginationControls.appendChild(pageInfo);

        paginationControls.appendChild(createButton('Next', page + 1, page === pageCount));
        paginationControls.appendChild(createButton('Last', pageCount, page === pageCount));
    }

    // Event Listeners
    filterBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            currentFilter = btn.dataset.type;
            filterBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentPage = 1;
            fetchTransactions();
        });
    });

    searchInput.addEventListener('input', () => {
        currentSearch = searchInput.value;
        currentPage = 1;
        fetchTransactions();
    });

    searchTransactionIdInput.addEventListener('input', () => {
        currentTransactionIdSearch = searchTransactionIdInput.value;
        currentPage = 1;
        fetchTransactions();
    });

    applyDateBtn.addEventListener('click', () => {
        dateFrom = dateFromInput.value;
        dateTo = dateToInput.value;
        currentPage = 1;
        fetchTransactions();
    });

    zoneFilter.addEventListener('change', (e) => {
        currentZone = e.target.value;
        currentArea = '';
        currentBranch = '';
        currentCO = '';
        updateAreaFilter();
        updateBranchFilter();
        updateCOFilter();
        currentPage = 1;
        fetchTransactions();
    });

    areaFilter.addEventListener('change', (e) => {
        currentArea = e.target.value;
        currentBranch = '';
        currentCO = '';
        updateBranchFilter();
        updateCOFilter();
        currentPage = 1;
        fetchTransactions();
    });

    branchFilter.addEventListener('change', (e) => {
        currentBranch = e.target.value;
        currentCO = '';
        updateCOFilter();
        currentPage = 1;
        fetchTransactions();
    });

    coFilter.addEventListener('change', (e) => {
        currentCO = e.target.value;
        currentPage = 1;
        fetchTransactions();
    });

    function updateAreaFilter() {
        const areas = allHierarchy.areas.filter(a => a.zone_id === currentZone);
        areaFilter.innerHTML = '<option value="">All Areas</option>';
        areas.forEach(area => {
            const option = document.createElement('option');
            option.value = area.id;
            option.textContent = area.name;
            areaFilter.appendChild(option);
        });
        areaFilter.disabled = !currentZone;
        areaFilter.value = currentArea; // Set selected value
    }

    function updateBranchFilter() {
        const branches = allHierarchy.branches.filter(b => b.area_id === currentArea);
        branchFilter.innerHTML = '<option value="">All Branches</option>';
        branches.forEach(branch => {
            const option = document.createElement('option');
            option.value = branch.id;
            option.textContent = branch.name;
            branchFilter.appendChild(option);
        });
        branchFilter.disabled = !currentArea;
        branchFilter.value = currentBranch; // Set selected value
    }

    function updateCOFilter() {
        const creditOfficers = Object.entries(allCreditOfficers).filter(([username, details]) => {
            return !currentBranch || details.branch_id === currentBranch;
        });
        coFilter.innerHTML = '<option value="">All COs</option>';
        creditOfficers.forEach(([username, details]) => {
            const option = document.createElement('option');
            option.value = username;
            option.textContent = details.full_name;
            coFilter.appendChild(option);
        });
        coFilter.disabled = !currentBranch;
        coFilter.value = currentCO; // Set selected value
    }

    // Client Details Modal
    tableBody.addEventListener('click', function(event) {
        if (event.target.classList.contains('client-btn')) {
            const clientName = event.target.dataset.clientName;
            showClientDetailsModal(clientName);
        }
    });

    function showClientDetailsModal(clientName) {
        const clientModal = document.getElementById('clientModal');
        const clientNameElement = document.getElementById('clientName');
        const clientDetailsDiv = document.getElementById('clientDetails');

        clientNameElement.textContent = clientName;
        clientDetailsDiv.innerHTML = 'Loading client details...';
        clientModal.style.display = 'flex';
        clientModal.classList.add('show');

        fetch(`transaction_manager.php?action=get_client_details&client=${encodeURIComponent(clientName)}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    clientDetailsDiv.innerHTML = data.html;
                } else {
                    clientDetailsDiv.innerHTML = `<p class="text-red-500">${data.message}</p>`;
                }
            })
            .catch(error => {
                console.error('Error fetching client details:', error);
                clientDetailsDiv.innerHTML = '<p class="text-red-500">Failed to load client details.</p>';
            });
    }

    // Initial filter setup
    updateAreaFilter();
    updateBranchFilter();
    updateCOFilter();
    fetchTransactions();
});

// Helper function for client button colors
function crc32(r) {
    for(var a, o = [], c = 0; c < 256; c++) {
        a = c;
        for(var f = 0; f < 8; f++) a = 1 & a ? 3988292384 ^ a >>> 1 : a >>> 1;
        o[c] = a
    }
    for(var n = -1, t = 0; t < r.length; t++) n = n >>> 8 ^ o[255 & (n ^ r.charCodeAt(t))];
    return (-1 ^ n) >>> 0
}

function showToast(message, type) {
    const toast = document.createElement('div');
    toast.classList.add('toast', type);
    toast.textContent = message;
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.classList.add('show');
    }, 100);

    setTimeout(() => {
        toast.classList.remove('show');
        toast.addEventListener('transitionend', () => toast.remove());
    }, 3000);
}

function editTransaction(transaction) {
    const editModal = document.getElementById('editModal');
    const editForm = document.getElementById('editForm');
    const editClient = document.getElementById('editClient');
    const editOfficer = document.getElementById('editOfficer');
    const editAmount = document.getElementById('editAmount');
    const editDate = document.getElementById('editDate');
    const editTransactionId = document.getElementById('editTransactionId');
    const editType = document.getElementById('editType');
    const editExtraFields = document.getElementById('editExtraFields');

    editClient.value = transaction.client;
    editOfficer.value = transaction.officer || 'N/A';
    editAmount.value = transaction.principal_amount || transaction.amount_collected || Math.abs(transaction.amount) || 0;
    editDate.value = new Date(transaction.date).toISOString().slice(0, 16);
    editTransactionId.value = transaction.transaction_id;
    editType.value = transaction.transaction_type;

    editExtraFields.innerHTML = ''; // Clear previous extra fields

    // Add specific fields based on transaction type
    if (transaction.transaction_type === 'disbursement') {
        editExtraFields.innerHTML += `
            <div class="form-field">
                <label for="editTotalPayable"><i class="fas fa-money-check-alt"></i>Total Payable</label>
                <input type="number" id="editTotalPayable" value="${transaction.total_payable || ''}">
            </div>
            <div class="form-field">
                <label for="editInstallmentAmount"><i class="fas fa-money-bill"></i>Installment Amount</label>
                <input type="number" id="editInstallmentAmount" value="${transaction.installment_amount || ''}">
            </div>
            <div class="form-field">
                <label for="editInterestRate"><i class="fas fa-percent"></i>Interest Rate (%)</label>
                <input type="number" id="editInterestRate" value="${transaction.interest_rate || ''}">
            </div>
            <div class="form-field">
                <label for="editLoanTerm"><i class="fas fa-calendar-alt"></i>Loan Term (months)</label>
                <input type="number" id="editLoanTerm" value="${transaction.loan_term || ''}">
            </div>
        `;
    } else if (transaction.transaction_type === 'loan_collection' || transaction.transaction_type === 'repayment') {
        editExtraFields.innerHTML += `
            <div class="form-field">
                <label for="editCollectionMethod"><i class="fas fa-cash-register"></i>Collection Method</label>
                <input type="text" id="editCollectionMethod" value="${transaction.collection_method || ''}">
            </div>
        `;
    } else if (transaction.transaction_type.startsWith('savings_')) {
        editExtraFields.innerHTML += `
            <div class="form-field">
                <label for="editSavingsType"><i class="fas fa-piggy-bank"></i>Savings Type</label>
                <input type="text" id="editSavingsType" value="${transaction.type || ''}" readonly>
            </div>
        `;
    }

    editModal.style.display = 'flex';
    editModal.classList.add('show');

    editForm.onsubmit = function(e) {
        e.preventDefault();
        const updatedData = {
            client: editClient.value,
            officer: editOfficer.value,
            date: new Date(editDate.value).toISOString(),
        };

        if (editType.value === 'disbursement') {
            updatedData.principal_amount = parseFloat(editAmount.value);
            updatedData.total_payable = parseFloat(document.getElementById('editTotalPayable').value);
            updatedData.installment_amount = parseFloat(document.getElementById('editInstallmentAmount').value);
            updatedData.interest_rate = parseFloat(document.getElementById('editInterestRate').value);
            updatedData.loan_term = parseInt(document.getElementById('editLoanTerm').value);
        } else if (editType.value === 'loan_collection' || editType.value === 'repayment') {
            updatedData.amount_collected = parseFloat(editAmount.value);
            updatedData.collection_method = document.getElementById('editCollectionMethod').value;
        } else if (editType.value.startsWith('savings_')) {
            updatedData.amount = parseFloat(editAmount.value) * (editType.value.includes('withdrawal') || editType.value.includes('return') ? -1 : 1);
        }

        fetch('transaction_manager.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'edit_transaction',
                transaction_id: editTransactionId.value,
                transaction_type: editType.value,
                data: JSON.stringify(updatedData),
                csrf_token: document.querySelector('input[name="csrf_token"]').value
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                closeEditModal();
                fetchTransactions(); // Refresh table
            } else {
                showToast(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('An error occurred.', 'error');
        });
    };
}

function deleteTransaction(transaction) {
    if (!confirm('Are you sure you want to delete this transaction? This action cannot be undone.')) {
        return;
    }

    fetch('transaction_manager.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            action: 'delete_transaction',
            transaction_id: transaction.transaction_id,
            transaction_type: transaction.transaction_type,
            csrf_token: document.querySelector('input[name="csrf_token"]').value
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            fetchTransactions(); // Refresh table
        } else {
            showToast(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('An error occurred.', 'error');
    });
}

function closeClientModal() {
    const modal = document.getElementById('clientModal');
    modal.style.display = 'none';
    modal.classList.remove('show');
}

function closeEditModal() {
    const modal = document.getElementById('editModal');
    modal.style.display = 'none';
    modal.classList.remove('show');
}