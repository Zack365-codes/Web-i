    </main>

    <!-- Footer -->
    <footer>
        <div class="container-fluid px-lg-4 text-center">
            <div class="row align-items-center">
                <div class="col-md-6 text-md-start">
                    <span><strong>Iron Store Management</strong> &copy; <?= date('Y') ?> - Dual Unit (KG, Feet & Piece) POS System</span>
                </div>
                <div class="col-md-6 text-md-end mt-2 mt-md-0">
                    <span class="text-muted"><i class="fa-solid fa-shield-halved me-1 text-primary"></i> Profit Privacy Protected</span>
                </div>
            </div>
        </div>
    </footer>

    <!-- Profit PIN / Password Modal -->
    <div class="modal fade" id="profitPinModal" tabindex="-1" aria-labelledby="profitPinModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
            <div class="modal-content shadow">
                
                <div class="modal-header bg-dark text-white py-3">
                    <h5 class="modal-title fw-bold" id="profitPinModalLabel">
                        <i class="fa-solid fa-lock text-warning me-2"></i>Profit Security Shield
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <p class="text-muted small mb-3">
                        <i class="fa-solid fa-circle-info text-primary me-1"></i> 
                        Profit and purchase rates are protected so customers at the counter cannot see your margins.
                    </p>

                    <!-- Form 1: Unlock Profit -->
                    <form method="POST" action="">
                        <label for="profit_pin_input" class="form-label fw-bold">Enter PIN to Reveal Profit:</label>
                        <div class="input-group input-group-lg mb-2">
                            <span class="input-group-text bg-light"><i class="fa-solid fa-key text-muted"></i></span>
                            <input type="password" id="profit_pin_input" name="profit_pin" class="form-control fw-bold text-center letter-spacing-lg" placeholder="••••" required autofocus maxlength="10">
                        </div>
                        <div class="form-text small mb-3">Default PIN is <code>1234</code>.</div>

                        <button type="submit" name="verify_profit_pin" class="btn btn-warning w-100 fw-bold py-2 shadow-sm">
                            <i class="fa-solid fa-lock-open me-2"></i> Unlock Profit
                        </button>
                    </form>

                    <hr class="my-4">

                    <!-- Form 2: Change PIN (Collapsible) -->
                    <div class="text-center">
                        <a class="text-decoration-none small fw-bold text-secondary" data-bs-toggle="collapse" href="#changePinCollapse" role="button">
                            <i class="fa-solid fa-gear me-1"></i> Change PIN Code
                        </a>
                    </div>

                    <div class="collapse mt-3" id="changePinCollapse">
                        <div class="p-3 bg-light rounded-3 border">
                            <form method="POST" action="">
                                <div class="mb-2">
                                    <label class="form-label small fw-bold">Current PIN:</label>
                                    <input type="password" name="current_pin" class="form-control form-control-sm" placeholder="Current PIN" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">New PIN:</label>
                                    <input type="password" name="new_pin" class="form-control form-control-sm" placeholder="Enter new PIN" required>
                                </div>
                                <button type="submit" name="change_profit_pin" class="btn btn-outline-dark btn-sm w-100 fw-bold">
                                    <i class="fa-solid fa-save me-1"></i> Save New PIN
                                </button>
                            </form>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5.3 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Main JS (Real-time calculation & UI logic with cache buster) -->
    <script src="assets/js/main.js?v=<?= time() ?>"></script>
</body>
</html>
