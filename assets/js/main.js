// assets/js/main.js
// Interactive calculations for Iron Store inventory, Pieces & live profit preview

document.addEventListener('DOMContentLoaded', function () {
    // Select input elements
    const costKgInput = document.getElementById('cost_price_per_kg');
    const sellKgInput = document.getElementById('sell_price_per_kg');
    const sellFootInput = document.getElementById('sell_price_per_foot');
    const sellPieceInput = document.getElementById('sell_price_per_piece');
    
    const weightFootInput = document.getElementById('weight_per_foot');
    const weightPieceInput = document.getElementById('weight_per_piece');
    const lengthFeetInput = document.getElementById('standard_length_feet');
    
    const stockPiecesInput = document.getElementById('stock_pieces');
    const stockKgInput = document.getElementById('total_stock_kg');

    // Helper conversion elements
    const helperPipeLength = document.getElementById('helper_pipe_length');
    const helperPipeWeight = document.getElementById('helper_pipe_weight');
    const btnApplyWeightPerFoot = document.getElementById('btn_apply_weight_foot');

    // Display preview elements
    const previewCostKg = document.getElementById('preview_cost_kg');
    const previewProfitKg = document.getElementById('preview_profit_kg');
    const previewMarginKg = document.getElementById('preview_margin_kg');
    
    const previewCostFoot = document.getElementById('preview_cost_foot');
    const previewProfitFoot = document.getElementById('preview_profit_foot');
    const previewMarginFoot = document.getElementById('preview_margin_foot');

    const previewCostPiece = document.getElementById('preview_cost_piece');
    const previewProfitPiece = document.getElementById('preview_profit_piece');
    const previewMarginPiece = document.getElementById('preview_margin_piece');

    const previewStockCost = document.getElementById('preview_stock_cost');
    const previewStockSell = document.getElementById('preview_stock_sell');
    const previewStockProfit = document.getElementById('preview_stock_profit');
    const previewStockPieces = document.getElementById('preview_stock_pieces');

    // 1. Sync Weight Per Foot <-> Weight Per Piece
    function syncFromWeightPerFoot() {
        const weightFoot = parseFloat(weightFootInput?.value) || 0;
        const lengthFeet = parseFloat(lengthFeetInput?.value) || 1;
        if (weightPieceInput && weightFoot > 0) {
            const calculatedPieceWeight = (weightFoot * lengthFeet).toFixed(3);
            weightPieceInput.value = parseFloat(calculatedPieceWeight);
        }
        syncStockFromPieces();
        calculateProfit();
    }

    function syncFromWeightPerPiece() {
        const weightPiece = parseFloat(weightPieceInput?.value) || 0;
        const lengthFeet = parseFloat(lengthFeetInput?.value) || 1;
        if (weightFootInput && weightPiece > 0 && lengthFeet > 0) {
            const calculatedFootWeight = (weightPiece / lengthFeet).toFixed(4);
            weightFootInput.value = parseFloat(calculatedFootWeight);
        }
        syncStockFromPieces();
        calculateProfit();
    }

    // 2. Sync Stock Pieces <-> Stock KG
    function syncStockFromPieces() {
        const pieces = parseFloat(stockPiecesInput?.value);
        let weightPiece = parseFloat(weightPieceInput?.value) || 0;
        if (weightPiece <= 0) {
            const weightFoot = parseFloat(weightFootInput?.value) || 0;
            const lengthFeet = parseFloat(lengthFeetInput?.value) || 20;
            weightPiece = weightFoot * lengthFeet;
        }

        if (!isNaN(pieces) && weightPiece > 0 && stockKgInput) {
            stockKgInput.value = (pieces * weightPiece).toFixed(2);
        }
        calculateProfit();
    }

    function syncStockFromKg() {
        const kg = parseFloat(stockKgInput?.value) || 0;
        let weightPiece = parseFloat(weightPieceInput?.value) || 0;
        if (weightPiece <= 0) {
            const weightFoot = parseFloat(weightFootInput?.value) || 0;
            const lengthFeet = parseFloat(lengthFeetInput?.value) || 20;
            weightPiece = weightFoot * lengthFeet;
        }

        if (stockPiecesInput && weightPiece > 0) {
            stockPiecesInput.value = (kg / weightPiece).toFixed(1);
        }
        calculateProfit();
    }

    // 3. Main Profit & Loss Calculation
    function calculateProfit() {
        const costKg = parseFloat(costKgInput?.value) || 0;
        const sellKg = parseFloat(sellKgInput?.value) || 0;
        const sellFoot = parseFloat(sellFootInput?.value) || 0;
        const sellPiece = parseFloat(sellPieceInput?.value) || 0;
        const weightFoot = parseFloat(weightFootInput?.value) || 0;
        const lengthFeet = parseFloat(lengthFeetInput?.value) || 20;

        // Auto determine weight per piece
        let weightPiece = parseFloat(weightPieceInput?.value) || 0;
        if (weightPiece <= 0 && weightFoot > 0 && lengthFeet > 0) {
            weightPiece = weightFoot * lengthFeet;
            if (weightPieceInput && document.activeElement !== weightPieceInput) {
                weightPieceInput.value = parseFloat(weightPiece.toFixed(3));
            }
        }

        const stockKg = parseFloat(stockKgInput?.value) || 0;

        // A. Profit per KG
        if (previewCostKg) {
            previewCostKg.textContent = 'Rs. ' + costKg.toFixed(2);
        }

        if (costKg > 0 && sellKg > 0) {
            const profitKg = sellKg - costKg;
            const marginKg = ((profitKg / costKg) * 100).toFixed(1);
            if (previewProfitKg) {
                previewProfitKg.textContent = (profitKg >= 0 ? '+Rs. ' : '-Rs. ') + Math.abs(profitKg).toFixed(2);
                previewProfitKg.className = profitKg >= 0 ? 'fs-5 fw-bold text-success' : 'fs-5 fw-bold text-danger';
            }
            if (previewMarginKg) {
                previewMarginKg.textContent = profitKg >= 0 ? marginKg + '% Margin' : Math.abs(marginKg) + '% Loss';
                previewMarginKg.className = profitKg >= 0 ? 'badge bg-success-subtle text-success' : 'badge bg-danger-subtle text-danger';
            }
        } else if (previewProfitKg) {
            previewProfitKg.textContent = 'Rs. 0.00';
            previewProfitKg.className = 'fs-5 fw-bold text-muted';
            if (previewMarginKg) previewMarginKg.textContent = '0% Margin';
        }

        // B. Profit per Foot
        const costFoot = costKg * weightFoot;
        if (previewCostFoot) previewCostFoot.textContent = 'Rs. ' + costFoot.toFixed(2);

        if (costFoot > 0 && sellFoot > 0) {
            const profitFoot = sellFoot - costFoot;
            const marginFoot = ((profitFoot / costFoot) * 100).toFixed(1);

            if (previewProfitFoot) {
                previewProfitFoot.textContent = (profitFoot >= 0 ? '+Rs. ' : '-Rs. ') + Math.abs(profitFoot).toFixed(2);
                previewProfitFoot.className = profitFoot >= 0 ? 'fs-5 fw-bold text-success' : 'fs-5 fw-bold text-danger';
            }
            if (previewMarginFoot) {
                previewMarginFoot.textContent = profitFoot >= 0 ? marginFoot + '% Margin' : Math.abs(marginFoot) + '% Loss';
                previewMarginFoot.className = profitFoot >= 0 ? 'badge bg-success-subtle text-success' : 'badge bg-danger-subtle text-danger';
            }
        } else {
            if (previewProfitFoot) {
                previewProfitFoot.textContent = 'Rs. 0.00';
                previewProfitFoot.className = 'fs-5 fw-bold text-muted';
            }
            if (previewMarginFoot) previewMarginFoot.textContent = '0% Margin';
        }

        // C. Profit per 1 Piece
        const costPiece = costKg * weightPiece;
        if (previewCostPiece) {
            previewCostPiece.textContent = 'Rs. ' + costPiece.toFixed(2);
        }

        if (sellPiece > 0) {
            if (costPiece > 0) {
                const profitPiece = sellPiece - costPiece;
                const marginPiece = ((profitPiece / costPiece) * 100).toFixed(1);

                if (previewProfitPiece) {
                    previewProfitPiece.textContent = (profitPiece >= 0 ? '+Rs. ' : '-Rs. ') + Math.abs(profitPiece).toFixed(2);
                    previewProfitPiece.className = profitPiece >= 0 ? 'fs-5 fw-bold text-success' : 'fs-5 fw-bold text-danger';
                }
                if (previewMarginPiece) {
                    previewMarginPiece.textContent = profitPiece >= 0 ? marginPiece + '% Margin' : Math.abs(marginPiece) + '% Loss';
                    previewMarginPiece.className = profitPiece >= 0 ? 'badge bg-success-subtle text-success' : 'badge bg-danger-subtle text-danger';
                }
            } else {
                if (previewProfitPiece) {
                    previewProfitPiece.textContent = 'Rs. ' + sellPiece.toFixed(2);
                    previewProfitPiece.className = 'fs-5 fw-bold text-primary';
                }
                if (previewMarginPiece) {
                    previewMarginPiece.textContent = 'Enter Weight & Cost';
                    previewMarginPiece.className = 'badge bg-secondary';
                }
            }
        } else {
            if (previewProfitPiece) {
                previewProfitPiece.textContent = 'Rs. 0.00';
                previewProfitPiece.className = 'fs-5 fw-bold text-muted';
            }
            if (previewMarginPiece) {
                previewMarginPiece.textContent = '0% Margin';
                previewMarginPiece.className = 'badge bg-secondary';
            }
        }

        // D. Total Stock Value & Potential Profit
        const totalCostVal = stockKg * costKg;
        let totalSellVal = 0;
        if (sellKg > 0) {
            totalSellVal = stockKg * sellKg;
        } else if (sellPiece > 0 && weightPiece > 0) {
            const pieces = stockKg / weightPiece;
            totalSellVal = pieces * sellPiece;
        }

        const totalProfitVal = totalSellVal - totalCostVal;

        if (previewStockCost) {
            previewStockCost.textContent = 'Rs. ' + totalCostVal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        if (previewStockSell) {
            previewStockSell.textContent = 'Rs. ' + totalSellVal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        if (previewStockProfit) {
            const sign = totalProfitVal >= 0 ? '+' : '-';
            previewStockProfit.textContent = sign + 'Rs. ' + Math.abs(totalProfitVal).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            previewStockProfit.className = totalProfitVal >= 0 ? 'fw-bold text-success fs-5' : 'fw-bold text-danger fs-5';
        }
        if (previewStockPieces) {
            if (weightPiece > 0) {
                const pieceCount = (stockKg / weightPiece).toFixed(1);
                previewStockPieces.textContent = `${pieceCount} Pieces in Stock`;
            } else {
                previewStockPieces.textContent = `0 Pieces`;
            }
        }
    }

    // Attach listeners on all input events
    const allWatchedInputs = [
        costKgInput, sellKgInput, sellFootInput, sellPieceInput,
        weightFootInput, weightPieceInput, lengthFeetInput,
        stockPiecesInput, stockKgInput
    ];

    allWatchedInputs.forEach(input => {
        if (input) {
            input.addEventListener('input', calculateProfit);
            input.addEventListener('keyup', calculateProfit);
            input.addEventListener('change', calculateProfit);
        }
    });

    if (weightFootInput) weightFootInput.addEventListener('input', syncFromWeightPerFoot);
    if (lengthFeetInput) lengthFeetInput.addEventListener('input', syncFromWeightPerFoot);
    if (weightPieceInput) weightPieceInput.addEventListener('input', syncFromWeightPerPiece);

    if (stockPiecesInput) stockPiecesInput.addEventListener('input', syncStockFromPieces);
    if (stockKgInput) stockKgInput.addEventListener('input', syncStockFromKg);

    // Helper: Calculate weight per foot from whole piece
    if (btnApplyWeightPerFoot && helperPipeLength && helperPipeWeight) {
        btnApplyWeightPerFoot.addEventListener('click', function () {
            const length = parseFloat(helperPipeLength.value) || 0;
            const totalWeight = parseFloat(helperPipeWeight.value) || 0;
            if (length > 0 && totalWeight > 0) {
                if (weightPieceInput) weightPieceInput.value = totalWeight;
                if (lengthFeetInput) lengthFeetInput.value = length;
                if (weightFootInput) weightFootInput.value = (totalWeight / length).toFixed(4);
                
                syncStockFromPieces();
                calculateProfit();
                
                if (weightPieceInput) {
                    weightPieceInput.classList.add('is-valid');
                    setTimeout(() => weightPieceInput.classList.remove('is-valid'), 1500);
                }
            } else {
                alert('Please enter both Piece Length (Feet) and Total Piece Weight (KG).');
            }
        });
    }

    // Initial calculation run
    calculateProfit();
});
