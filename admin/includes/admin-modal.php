<?php

if (!function_exists('renderAdminModalStart')) {
    function renderAdminModalStart(string $id, string $title, string $contentClass = ''): void
    {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
        $titleId = $safeId . '-title';
        $classes = trim('modal-content ' . $contentClass);
        ?>
        <div class="modal-overlay" id="<?php echo htmlspecialchars($safeId); ?>">
            <div class="<?php echo htmlspecialchars($classes); ?>">
                <div class="modal-header">
                    <h3 id="<?php echo htmlspecialchars($titleId); ?>"><?php echo htmlspecialchars($title); ?></h3>
                    <button
                        class="modal-close"
                        type="button"
                        aria-label="Close modal"
                        onclick="closeAdminModal('<?php echo htmlspecialchars($safeId); ?>')"
                    >&times;</button>
                </div>
                <div class="modal-body">
        <?php
    }
}

if (!function_exists('renderAdminModalEnd')) {
    function renderAdminModalEnd(): void
    {
        ?>
                </div>
            </div>
        </div>
        <?php
    }
}

if (!function_exists('renderAdminModalScript')) {
    function renderAdminModalScript(): void
    {
        static $printed = false;
        if ($printed) {
            return;
        }
        $printed = true;
        ?>
        <script>
            function openAdminModal(modalId) {
                var modal = document.getElementById(modalId);
                if (!modal) return;
                modal.classList.add('active');
                document.body.classList.add('modal-open');
            }

            function closeAdminModal(modalId) {
                var modal = document.getElementById(modalId);
                if (!modal) return;
                modal.classList.remove('active');
                if (!document.querySelector('.modal-overlay.active')) {
                    document.body.classList.remove('modal-open');
                }
            }

            function bindAdminModal(modalId) {
                var modal = document.getElementById(modalId);
                if (!modal || modal.dataset.bound === '1') return;

                modal.addEventListener('click', function (e) {
                    if (e.target === modal) {
                        closeAdminModal(modalId);
                    }
                });

                modal.dataset.bound = '1';
            }

            document.addEventListener('keydown', function (e) {
                if (e.key !== 'Escape') return;
                var active = document.querySelector('.modal-overlay.active');
                if (active && active.id) {
                    closeAdminModal(active.id);
                }
            });
        </script>
        <?php
    }
}

