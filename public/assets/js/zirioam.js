/**
 * ZIRI OAM — Scripts Client JavaScript Natif (Sans dépendance lourde)
 */

document.addEventListener('DOMContentLoaded', function () {
    // Gestion de la copie du mot de passe temporaire
    const copyBtn = document.getElementById('btn-copy-password');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            const pwdInput = document.getElementById('temp-password-field');
            if (pwdInput) {
                navigator.clipboard.writeText(pwdInput.value).then(() => {
                    const originalText = copyBtn.innerHTML;
                    copyBtn.innerHTML = '<span class="text-success">✓ Copié !</span>';
                    setTimeout(() => {
                        copyBtn.innerHTML = originalText;
                    }, 2000);
                }).catch(err => {
                    console.error('Erreur lors de la copie :', err);
                });
            }
        });
    }

    // Confirmation d'action sensible
    const confirmActions = document.querySelectorAll('[data-confirm]');
    confirmActions.forEach(btn => {
        btn.addEventListener('click', function (e) {
            const msg = this.getAttribute('data-confirm') || "Confirmez-vous cette opération ?";
            if (!confirm(msg)) {
                e.preventDefault();
                return false;
            }
        });
    });

    // Filtre dynamique de recherche dans les tableaux
    const searchInputs = document.querySelectorAll('[data-table-filter]');
    searchInputs.forEach(input => {
        input.addEventListener('keyup', function () {
            const targetTableId = this.getAttribute('data-table-filter');
            const targetTable = document.getElementById(targetTableId);
            if (!targetTable) return;

            const filterValue = this.value.toLowerCase().trim();
            const rows = targetTable.querySelectorAll('tbody tr');

            rows.forEach(row => {
                const text = row.innerText.toLowerCase();
                if (text.includes(filterValue)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    });
});

