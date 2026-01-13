/**
 * Chess Game Renderer
 */
(function($) {
    'use strict';

    const PIECE_SYMBOLS = {
        'K': '♔', 'Q': '♕', 'R': '♖', 'B': '♗', 'N': '♘', 'P': '♙',
        'k': '♚', 'q': '♛', 'r': '♜', 'b': '♝', 'n': '♞', 'p': '♟',
        '': ''
    };

    const ChessRenderer = {
        selectedPiece: null,
        validMoves: [],
        mySeat: null,
        onMove: null,
        state: null,

        render: function(stateData, mySeat, onMove) {
            this.mySeat = mySeat;
            this.onMove = onMove;
            this.state = stateData.state;
            this.selectedPiece = null;
            this.validMoves = [];

            const board = this.state.board;
            const isMyTurn = this.state.current_turn === mySeat;
            const flip = mySeat === 1; // Flip board for black

            let html = '<div class="chess-board">';

            for (let displayRow = 0; displayRow < 8; displayRow++) {
                const row = flip ? displayRow : 7 - displayRow;
                html += '<div class="chess-row">';

                for (let displayCol = 0; displayCol < 8; displayCol++) {
                    const col = flip ? 7 - displayCol : displayCol;
                    const isLight = (row + col) % 2 === 0;
                    const piece = board[row][col];
                    const cellClass = isLight ? 'chess-cell-light' : 'chess-cell-dark';

                    html += `<div class="chess-cell ${cellClass}" data-row="${row}" data-col="${col}">`;

                    if (piece) {
                        const pieceColor = piece === piece.toUpperCase() ? 'chess-white-piece' : 'chess-black-piece';
                        html += `<span class="chess-piece ${pieceColor}" data-row="${row}" data-col="${col}">
                            ${PIECE_SYMBOLS[piece]}
                        </span>`;
                    }

                    html += '</div>';
                }

                html += '</div>';
            }

            html += '</div>';

            // Game info
            html += '<div class="chess-info">';
            if (this.state.in_check) {
                html += '<div class="chess-check-warning">Check!</div>';
            }
            html += '</div>';

            $('#cga-game-board').html(html);

            if (isMyTurn) {
                this.bindBoardEvents();
            }
        },

        isMyPiece: function(piece) {
            if (!piece) return false;
            if (this.mySeat === 0) {
                return piece === piece.toUpperCase();
            }
            return piece === piece.toLowerCase();
        },

        bindBoardEvents: function() {
            const self = this;

            $('.chess-piece').on('click', function(e) {
                e.stopPropagation();
                const row = parseInt($(this).data('row'));
                const col = parseInt($(this).data('col'));
                const piece = self.state.board[row][col];

                if (self.isMyPiece(piece)) {
                    self.selectPiece(row, col);
                } else if (self.selectedPiece) {
                    // Try to capture
                    self.tryMove(row, col);
                }
            });

            $('.chess-cell').on('click', function() {
                if (!self.selectedPiece) return;

                const row = parseInt($(this).data('row'));
                const col = parseInt($(this).data('col'));

                self.tryMove(row, col);
            });
        },

        selectPiece: function(row, col) {
            $('.chess-cell').removeClass('chess-selected chess-valid-move chess-valid-capture');

            this.selectedPiece = { row, col };
            $(`.chess-cell[data-row="${row}"][data-col="${col}"]`).addClass('chess-selected');

            // Show valid moves (simplified - server validates)
            this.showPossibleMoves(row, col);
        },

        showPossibleMoves: function(row, col) {
            const piece = this.state.board[row][col].toLowerCase();
            const board = this.state.board;

            // Simple visual hints - actual validation is server-side
            for (let r = 0; r < 8; r++) {
                for (let c = 0; c < 8; c++) {
                    if (r === row && c === col) continue;

                    const target = board[r][c];
                    const cell = $(`.chess-cell[data-row="${r}"][data-col="${c}"]`);

                    if (target && !this.isMyPiece(target)) {
                        cell.addClass('chess-valid-capture');
                    } else if (!target) {
                        // Could add move highlighting here
                    }
                }
            }
        },

        tryMove: function(toRow, toCol) {
            if (!this.selectedPiece) return;

            const move = {
                from: { row: this.selectedPiece.row, col: this.selectedPiece.col },
                to: { row: toRow, col: toCol }
            };

            // Check for pawn promotion
            const piece = this.state.board[this.selectedPiece.row][this.selectedPiece.col];
            if (piece.toLowerCase() === 'p') {
                const promoRow = this.mySeat === 0 ? 0 : 7;
                if (toRow === promoRow) {
                    move.promotion = this.askPromotion();
                }
            }

            if (this.onMove) {
                this.onMove(move);
            }
        },

        askPromotion: function() {
            // Simple prompt - could be improved to a modal
            const choice = prompt('Promote to: Q (Queen), R (Rook), B (Bishop), N (Knight)', 'Q');
            const valid = ['q', 'r', 'b', 'n'];
            const lower = (choice || 'q').toLowerCase();
            return valid.includes(lower) ? lower : 'q';
        }
    };

    window.CGAGames = window.CGAGames || {};
    window.CGAGames.chess = ChessRenderer;

})(jQuery);
