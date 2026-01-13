/**
 * Checkers Game Renderer
 */
(function($) {
    'use strict';

    const PIECE_SYMBOLS = {
        0: '',      // Empty
        1: '⚫',    // Black
        2: '⚪',    // White
        3: '♚',    // Black King
        4: '♔'     // White King
    };

    const CheckersRenderer = {
        selectedPiece: null,
        validMoves: [],
        mySeat: null,
        onMove: null,

        render: function(stateData, mySeat, onMove) {
            this.mySeat = mySeat;
            this.onMove = onMove;
            this.selectedPiece = null;
            this.validMoves = [];

            const state = stateData.state;
            const board = state.board;
            const isMyTurn = state.current_turn === mySeat;

            // Build board HTML
            let html = '<div class="checkers-board">';

            for (let row = 0; row < 8; row++) {
                html += '<div class="checkers-row">';

                for (let col = 0; col < 8; col++) {
                    const isLight = (row + col) % 2 === 0;
                    const piece = board[row][col];
                    const cellClass = isLight ? 'checkers-cell-light' : 'checkers-cell-dark';
                    const pieceClass = this.getPieceClass(piece);

                    html += `<div class="checkers-cell ${cellClass}" data-row="${row}" data-col="${col}">`;

                    if (piece > 0) {
                        html += `<span class="checkers-piece ${pieceClass}" data-row="${row}" data-col="${col}">
                            ${PIECE_SYMBOLS[piece]}
                        </span>`;
                    }

                    html += '</div>';
                }

                html += '</div>';
            }

            html += '</div>';

            // Add captured count
            html += '<div class="checkers-info">';
            html += `<div class="checkers-captured">`;
            html += `<span>Your captures: ${state.captured[mySeat] || 0}</span>`;
            html += `<span>Opponent: ${state.captured[mySeat === 0 ? 1 : 0] || 0}</span>`;
            html += '</div>';

            if (state.must_capture) {
                html += '<div class="checkers-notice">You must capture!</div>';
            }
            if (state.multi_jump) {
                html += '<div class="checkers-notice">Continue your jump!</div>';
            }
            html += '</div>';

            $('#cga-game-board').html(html);

            // Bind click events if it's my turn
            if (isMyTurn) {
                this.bindBoardEvents(state);
            }
        },

        getPieceClass: function(piece) {
            switch (piece) {
                case 1: return 'checkers-black';
                case 2: return 'checkers-white';
                case 3: return 'checkers-black checkers-king';
                case 4: return 'checkers-white checkers-king';
                default: return '';
            }
        },

        isMyPiece: function(piece) {
            if (this.mySeat === 0) {
                return piece === 1 || piece === 3; // Black
            }
            return piece === 2 || piece === 4; // White
        },

        bindBoardEvents: function(state) {
            const self = this;

            // Click on piece to select
            $('.checkers-piece').on('click', function(e) {
                e.stopPropagation();
                const row = parseInt($(this).data('row'));
                const col = parseInt($(this).data('col'));
                const piece = state.board[row][col];

                if (self.isMyPiece(piece)) {
                    self.selectPiece(row, col, state);
                }
            });

            // Click on cell to move
            $('.checkers-cell').on('click', function() {
                if (!self.selectedPiece) return;

                const row = parseInt($(this).data('row'));
                const col = parseInt($(this).data('col'));

                self.tryMove(row, col);
            });
        },

        selectPiece: function(row, col, state) {
            // Clear previous selection
            $('.checkers-cell').removeClass('checkers-selected checkers-valid-move');

            // If in multi-jump, can only select that piece
            if (state.multi_jump) {
                if (state.multi_jump.row !== row || state.multi_jump.col !== col) {
                    return;
                }
            }

            this.selectedPiece = { row, col };

            // Highlight selected piece
            $(`.checkers-cell[data-row="${row}"][data-col="${col}"]`).addClass('checkers-selected');

            // Calculate and show valid moves
            this.validMoves = this.calculateValidMoves(state, row, col);

            for (const move of this.validMoves) {
                $(`.checkers-cell[data-row="${move.to.row}"][data-col="${move.to.col}"]`)
                    .addClass('checkers-valid-move');
            }
        },

        calculateValidMoves: function(state, row, col) {
            const piece = state.board[row][col];
            const isKing = piece === 3 || piece === 4;
            const moves = [];
            const jumps = [];

            // Direction based on piece color
            const forward = this.mySeat === 0 ? 1 : -1;
            const directions = isKing
                ? [[-1, -1], [-1, 1], [1, -1], [1, 1]]
                : [[forward, -1], [forward, 1]];

            // Check for jumps
            for (const [dr, dc] of directions) {
                const midRow = row + dr;
                const midCol = col + dc;
                const toRow = row + dr * 2;
                const toCol = col + dc * 2;

                if (this.inBounds(toRow, toCol)) {
                    const midPiece = state.board[midRow][midCol];
                    const destPiece = state.board[toRow][toCol];

                    if (this.isOpponentPiece(midPiece) && destPiece === 0) {
                        jumps.push({
                            from: { row, col },
                            to: { row: toRow, col: toCol },
                            is_capture: true
                        });
                    }
                }
            }

            // If jumps available or must capture, only return jumps
            if (jumps.length > 0 || state.must_capture) {
                return jumps;
            }

            // Check for simple moves
            for (const [dr, dc] of directions) {
                const toRow = row + dr;
                const toCol = col + dc;

                if (this.inBounds(toRow, toCol) && state.board[toRow][toCol] === 0) {
                    moves.push({
                        from: { row, col },
                        to: { row: toRow, col: toCol },
                        is_capture: false
                    });
                }
            }

            return moves;
        },

        isOpponentPiece: function(piece) {
            if (this.mySeat === 0) {
                return piece === 2 || piece === 4; // White
            }
            return piece === 1 || piece === 3; // Black
        },

        inBounds: function(row, col) {
            return row >= 0 && row < 8 && col >= 0 && col < 8;
        },

        tryMove: function(toRow, toCol) {
            const move = this.validMoves.find(m =>
                m.to.row === toRow && m.to.col === toCol
            );

            if (move && this.onMove) {
                this.onMove(move);
            }
        }
    };

    // Register with game engine
    window.CGAGames = window.CGAGames || {};
    window.CGAGames.checkers = CheckersRenderer;

})(jQuery);
