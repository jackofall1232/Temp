/**
 * Hearts Game Renderer
 */
(function($) {
    'use strict';

    const HeartsRenderer = {
        mySeat: null,
        onMove: null,
        state: null,
        selectedPassCards: [],

        render: function(stateData, mySeat, onMove) {
            this.mySeat = mySeat;
            this.onMove = onMove;
            this.state = stateData.state;

            if (this.state.phase === 'passing') {
                this.renderPassingPhase();
            } else if (this.state.phase === 'playing') {
                this.renderPlayingPhase();
            } else if (this.state.phase === 'round_end') {
                this.renderRoundEnd();
            }
        },

        renderPassingPhase: function() {
            const direction = this.state.pass_direction;
            const mySelection = this.state.pass_selections[this.mySeat] || [];
            const isComplete = this.state.passes_complete[this.mySeat];

            let html = '<div class="hearts-passing">';
            html += `<h3>Pass 3 cards ${direction}</h3>`;

            // Show other players' status
            html += '<div class="pass-status">';
            for (let seat = 0; seat < 4; seat++) {
                if (seat === this.mySeat) continue;
                const player = this.state.players[seat];
                const status = this.state.pass_selections[seat] === 'ready' ? '✓ Ready' : '⏳ Selecting';
                html += `<span class="player-pass-status">${player.name}: ${status}</span>`;
            }
            html += '</div>';

            // My hand
            const myHand = this.state.hands[this.mySeat];
            html += '<div class="my-hand-area">';

            if (isComplete) {
                html += '<p>Waiting for other players...</p>';
                html += CGACards.renderHand(myHand, {
                    selectedCards: mySelection,
                    validCards: []
                });
            } else {
                html += CGACards.renderHand(myHand, {
                    selectedCards: this.selectedPassCards,
                    validCards: myHand.map(c => c.id)
                });

                html += `<button id="hearts-confirm-pass" class="cga-btn cga-btn-primary"
                         ${this.selectedPassCards.length !== 3 ? 'disabled' : ''}>
                    Confirm Pass (${this.selectedPassCards.length}/3)
                </button>`;
            }

            html += '</div>';
            html += '</div>';

            $('#cga-game-board').html(html);
            this.bindPassingEvents();
        },

        bindPassingEvents: function() {
            const self = this;

            CGACards.bindCardClicks('#cga-game-board', (card) => {
                if (self.state.passes_complete[self.mySeat]) return;

                const idx = self.selectedPassCards.indexOf(card.id);
                if (idx >= 0) {
                    self.selectedPassCards.splice(idx, 1);
                } else if (self.selectedPassCards.length < 3) {
                    self.selectedPassCards.push(card.id);
                }

                self.renderPassingPhase();
            });

            $('#hearts-confirm-pass').on('click', function() {
                if (self.selectedPassCards.length === 3 && self.onMove) {
                    self.onMove({ cards: self.selectedPassCards });
                    self.selectedPassCards = [];
                }
            });
        },

        renderPlayingPhase: function() {
            const currentTurn = this.state.current_turn;
            const isMyTurn = currentTurn === this.mySeat;
            const myHand = this.state.hands[this.mySeat];

            let html = '<div class="hearts-table cga-table">';

            // Render 4 player positions
            const positions = this.getPositions();

            positions.forEach(pos => {
                html += `<div class="cga-seat-${pos.position}">`;
                html += this.renderPlayerArea(pos.seat, pos.position);
                html += '</div>';
            });

            // Center - trick area
            html += '<div class="cga-table-center">';
            html += '<div class="cga-play-area">';

            if (this.state.trick.length > 0) {
                html += CGACards.renderTrick(this.state.trick, this.state.players);
            } else {
                html += '<div class="trick-empty">Play Area</div>';
            }

            html += '</div>';

            // Hearts broken indicator
            if (this.state.hearts_broken) {
                html += '<div class="hearts-broken-indicator">💔 Hearts Broken</div>';
            }

            html += '</div>'; // center
            html += '</div>'; // table

            // My hand at bottom
            html += '<div class="my-hand-section">';

            const validMoves = isMyTurn ? this.getValidCardIds() : [];

            html += CGACards.renderHand(myHand, {
                selectedCards: [],
                validCards: validMoves
            });

            html += '</div>';

            // Scoreboard
            html += this.renderScoreboard();

            $('#cga-game-board').html(html);

            if (isMyTurn) {
                this.bindPlayingEvents();
            }
        },

        getPositions: function() {
            // Position players relative to current player (me at bottom)
            const positions = ['bottom', 'left', 'top', 'right'];
            const result = [];

            for (let i = 0; i < 4; i++) {
                const seat = (this.mySeat + i) % 4;
                result.push({
                    seat: seat,
                    position: positions[i]
                });
            }

            return result;
        },

        renderPlayerArea: function(seat, position) {
            const player = this.state.players[seat];
            const isActive = this.state.current_turn === seat;
            const isMe = seat === this.mySeat;
            const cardCount = isMe ? 0 : this.state.hands[seat]; // Count for opponents

            let html = CGACards.renderPlayerInfo(player, {
                isActive: isActive,
                showScore: true,
                score: this.state.round_scores[seat],
                extras: `<div class="tricks">Tricks: ${this.state.tricks_taken[seat]}</div>`
            });

            if (!isMe && cardCount > 0) {
                const vertical = position === 'left' || position === 'right';
                html += CGACards.renderOpponentHand(cardCount, { vertical });
            }

            return html;
        },

        getValidCardIds: function() {
            const myHand = this.state.hands[this.mySeat];
            const validIds = [];

            // Simplified client-side validation - server will verify
            const trick = this.state.trick;
            const isLeading = trick.length === 0;
            const isFirstTrick = Object.values(this.state.tricks_taken).every(t => t === 0);

            if (isLeading && isFirstTrick) {
                // Must lead 2 of clubs
                const twoClubs = myHand.find(c => c.suit === 'clubs' && c.rank === '2');
                if (twoClubs) return [twoClubs.id];
            }

            if (isLeading) {
                // Can lead anything (hearts if broken or only hearts)
                if (this.state.hearts_broken) {
                    return myHand.map(c => c.id);
                }
                const nonHearts = myHand.filter(c => c.suit !== 'hearts');
                return nonHearts.length > 0 ? nonHearts.map(c => c.id) : myHand.map(c => c.id);
            }

            // Must follow suit
            const leadSuit = trick[0].card.suit;
            const suitCards = myHand.filter(c => c.suit === leadSuit);

            if (suitCards.length > 0) {
                return suitCards.map(c => c.id);
            }

            // Can play anything
            return myHand.map(c => c.id);
        },

        bindPlayingEvents: function() {
            const self = this;

            CGACards.bindCardClicks('.my-hand-section', (card) => {
                if (self.onMove) {
                    self.onMove({ card_id: card.id });
                }
            });
        },

        renderRoundEnd: function() {
            let html = '<div class="hearts-round-end">';
            html += '<h2>Round Complete!</h2>';

            // Check for shoot the moon
            const moonShooter = this.state.round_scores.findIndex(s => s === 0 &&
                this.state.round_scores.filter(x => x === 26).length === 3);

            if (moonShooter >= 0) {
                html += `<div class="moon-shot">🌙 ${this.state.players[moonShooter].name} shot the moon!</div>`;
            }

            html += this.renderScoreboard();

            html += '<button id="hearts-next-round" class="cga-btn cga-btn-primary">Next Round</button>';
            html += '</div>';

            $('#cga-game-board').html(html);

            // Bind next round button
            $('#hearts-next-round').on('click', () => {
                if (this.onMove) {
                    this.onMove({ action: 'next_round' });
                }
            });
        },

        renderScoreboard: function() {
            let html = '<div class="hearts-scoreboard cga-scoreboard">';
            html += '<table>';
            html += '<tr><th>Player</th><th>Round</th><th>Total</th></tr>';

            for (let seat = 0; seat < 4; seat++) {
                const player = this.state.players[seat];
                const isMe = seat === this.mySeat;
                html += `<tr class="${isMe ? 'my-score' : ''}">
                    <td>${player.name}${isMe ? ' (You)' : ''}</td>
                    <td>${this.state.round_scores[seat]}</td>
                    <td>${this.state.total_scores[seat]}</td>
                </tr>`;
            }

            html += '</table></div>';
            return html;
        }
    };

    window.CGAGames = window.CGAGames || {};
    window.CGAGames.hearts = HeartsRenderer;

})(jQuery);
