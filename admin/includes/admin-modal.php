<?php

if (!function_exists('renderAdminModalStart')) {
    /**
     * Opens a standard modal (overlay > content > header + body).
     * Pass $formId (and optional $formAction) to wrap header, body and footer in one POST form, so a submit
     * button in renderAdminModalFooterStart() stays inside the form.
     */
    function renderAdminModalStart(string $id, string $title, string $contentClass = '', string $formId = '', string $formAction = ''): void
    {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
        $titleId = $safeId . '-title';
        $classes = trim('modal-content ' . $contentClass);
        $GLOBALS['rh_admin_modal_state'][] = ['form' => $formId !== '', 'footer' => false];
        ?>
        <div class="modal-overlay" id="<?php echo htmlspecialchars($safeId); ?>">
            <div class="<?php echo htmlspecialchars($classes); ?>">
                <?php if ($formId !== ''): ?>
                <form method="POST" id="<?php echo htmlspecialchars(preg_replace('/[^a-zA-Z0-9_-]/', '', $formId)); ?>"<?php if ($formAction !== ''): ?> action="<?php echo htmlspecialchars($formAction); ?>"<?php endif; ?>>
                <?php endif; ?>
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

if (!function_exists('renderAdminModalFooterStart')) {
    /** Closes the body and opens the standard .modal-footer action row. */
    function renderAdminModalFooterStart(): void
    {
        $last = count($GLOBALS['rh_admin_modal_state'] ?? []) - 1;
        if ($last >= 0) {
            $GLOBALS['rh_admin_modal_state'][$last]['footer'] = true;
        }
        ?>
                </div>
                <div class="modal-footer">
        <?php
    }
}

if (!function_exists('renderAdminModalEnd')) {
    function renderAdminModalEnd(): void
    {
        $state = array_pop($GLOBALS['rh_admin_modal_state']) ?: ['form' => false, 'footer' => false];
        ?>
                </div>
                <?php if (!empty($state['form'])): ?>
                </form>
                <?php endif; ?>
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


