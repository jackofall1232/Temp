/**
 * Canasta Game Renderer
 * Rummy-style game with melds and canastas
 */
(function($) {
    'use strict';

    const CanastaRenderer = {
        mySeat: null,
        onMove: null,
        state: null,
        selectedCards: [],

        render: function(stateData, mySeat, onMove) {
            this.mySeat = mySeat;
            this.onMove = onMove;
            this.state = stateData.state;

            if (this.state.phase === 'draw') {
                this.renderDrawPhase();
            } else if (this.state.phase === 'meld') {
                this.renderMeldPhase();
            } else if (this.state.phase === 'discard') {
                this.renderDiscardPhase();
            } else if (this.state.phase === 'hand_end') {
                this.renderHandEnd();
            }
        },

        renderDrawPhase: function() {
            const isMyTurn = this.state.current_turn === this.mySeat;

            let html = '<div class="canasta-game">';
            html += this.renderGameHeader();
            html += this.renderPlayArea();

            if (isMyTurn) {
                html += '<div class="draw-options">';
                html += '<h3>Your Turn - Draw</h3>';
                html += '<button id="canasta-draw-deck" class="cga-btn cga-btn-primary">Draw from Deck</button>';

                if (this.state.discard_pile.length > 0) {
                    const topCard = this.state.discard_pile[this.state.discard_pile.length - 1];
                    html += `<button id="canasta-draw-pile" class="cga-btn cga-btn-secondary">`;
                    html += `Take Discard Pile (${topCard.rank}${this.getSuitSymbol(topCard.suit)})`;
                    html += `</button>`;
                }

                html += '</div>';
            } else {
                const player = this.state.players[this.state.current_turn];
                html += `<p>Waiting for ${player.name} to draw...</p>`;
            }

            html += this.renderMyHand(false);
            html += '</div>';

            $('#cga-game-board').html(html);

            if (isMyTurn) {
                this.bindDrawEvents();
            }
        },

        renderMeldPhase: function() {
            const isMyTurn = this.state.current_turn === this.mySeat;

            let html = '<div class="canasta-game">';
            html += this.renderGameHeader();
            html += this.renderPlayArea();

            if (isMyTurn) {
                html += '<div class="meld-controls">';
                html += '<h3>Meld Phase</h3>';
                html += '<p>Select cards to meld (minimum 3 of same rank)</p>';

                html += '<button id="canasta-create-meld" class="cga-btn cga-btn-primary"';
                html += this.selectedCards.length < 3 ? ' disabled' : '';
                html += `>Create New Meld (${this.selectedCards.length} cards)</button>`;

                html += '<button id="canasta-add-to-meld" class="cga-btn cga-btn-secondary"';
                html += this.selectedCards.length < 1 ? ' disabled' : '';
                html += '>Add to Existing Meld</button>';

                html += '<button id="canasta-skip-meld" class="cga-btn">Skip Melding</button>';
                html += '</div>';
            }

            html += this.renderMyHand(isMyTurn);
            html += '</div>';

            $('#cga-game-board').html(html);

            if (isMyTurn) {
                this.bindMeldEvents();
            }
        },

        renderDiscardPhase: function() {
            const isMyTurn = this.state.current_turn === this.mySeat;

            let html = '<div class="canasta-game">';
            html += this.renderGameHeader();
            html += this.renderPlayArea();

            if (isMyTurn) {
                html += '<div class="discard-prompt">';
                html += '<h3>Select a card to discard</h3>';
                html += '</div>';
            }

            html += this.renderMyHand(isMyTurn);
            html += '</div>';

            $('#cga-game-board').html(html);

            if (isMyTurn) {
                this.bindDiscardEvents();
            }
        },

        renderGameHeader: function() {
            let html = '<div class="canasta-header">';
            html += '<div class="game-info">';
            html += `<span>Round ${this.state.round_number}</span>`;
            html += ` | <span>Deck: ${this.state.deck_count} cards</span>`;
            html += ` | <span>Discard: ${this.state.discard_pile.length} cards</span>`;
            html += '</div>';

            // Team scores
            if (this.state.has_teams) {
                html += '<div class="team-scores">';
                html += `<span class="team-1">Team 1: ${this.state.team_scores[0]}</span>`;
                html += ` | `;
                html += `<span class="team-2">Team 2: ${this.state.team_scores[1]}</span>`;
                html += '</div>';
            }

            html += '</div>';
            return html;
        },

        renderPlayArea: function() {
            let html = '<div class="canasta-play-area">';

            // All players and their melds
            for (let seat = 0; seat < this.state.players.length; seat++) {
                const player = this.state.players[seat];
                const isMe = seat === this.mySeat;
                const isActive = seat === this.state.current_turn;
                const melds = this.state.melds[seat] || [];

                html += `<div class="player-area ${isActive ? 'active' : ''} ${isMe ? 'my-area' : ''}">`;
                html += `<h4>${player.name}${isMe ? ' (You)' : ''}</h4>`;

                // Player melds
                if (melds.length > 0) {
                    html += '<div class="player-melds">';
                    melds.forEach((meld, idx) => {
                        html += this.renderMeld(meld, idx);
                    });
                    html += '</div>';
                } else {
                    html += '<p class="no-melds">No melds yet</p>';
                }

                // Card count if not me
                if (!isMe) {
                    const handSize = Array.isArray(this.state.hands[seat])
                        ? this.state.hands[seat].length
                        : this.state.hands[seat];
                    html += `<div class="card-count">${handSize} cards in hand</div>`;
                }

                html += '</div>';
            }

            // Discard pile
            html += '<div class="discard-pile-display">';
            html += '<h4>Discard Pile</h4>';
            if (this.state.discard_pile.length > 0) {
                const topCard = this.state.discard_pile[this.state.discard_pile.length - 1];
                html += CGACards.renderCard(topCard, { faceUp: true });
                if (this.state.discard_pile.length > 1) {
                    html += `<span class="pile-count">+${this.state.discard_pile.length - 1} more</span>`;
                }
            } else {
                html += '<p>Empty</p>';
            }
            html += '</div>';

            html += '</div>';
            return html;
        },

        renderMeld: function(meld, index) {
            let html = `<div class="meld" data-meld-index="${index}">`;

            const isCanasta = meld.cards.length >= 7;
            const rank = meld.cards[0].rank;

            html += `<div class="meld-header">`;
            html += `<span class="meld-rank">${rank}'s</span>`;
            if (isCanasta) {
                const isNatural = meld.cards.every(c => c.rank === rank);
                html += ` <span class="canasta-badge ${isNatural ? 'natural' : 'mixed'}">`;
                html += isNatural ? 'Natural Canasta!' : 'Mixed Canasta';
                html += '</span>';
            }
            html += ` <span class="meld-count">(${meld.cards.length} cards)</span>`;
            html += '</div>';

            html += '<div class="meld-cards">';
            meld.cards.forEach(card => {
                html += CGACards.renderCard(card, { faceUp: true, compact: true });
            });
            html += '</div>';

            html += '</div>';
            return html;
        },

        renderMyHand: function(allowSelection) {
            const myHand = this.state.hands[this.mySeat];

            let html = '<div class="my-hand-section">';
            html += '<h4>Your Hand</h4>';

            html += CGACards.renderHand(myHand, {
                selectedCards: this.selectedCards,
                validCards: allowSelection ? myHand.map(c => c.id) : [],
                sortByRank: true
            });

            html += '</div>';
            return html;
        },

        bindDrawEvents: function() {
            const self = this;

            $('#canasta-draw-deck').on('click', function() {
                if (self.onMove) {
                    self.onMove({ action: 'draw_deck' });
                }
            });

            $('#canasta-draw-pile').on('click', function() {
                if (self.onMove) {
                    self.onMove({ action: 'draw_pile' });
                }
            });
        },

        bindMeldEvents: function() {
            const self = this;

            CGACards.bindCardClicks('.my-hand-section', (card) => {
                const idx = self.selectedCards.indexOf(card.id);
                if (idx >= 0) {
                    self.selectedCards.splice(idx, 1);
                } else {
                    self.selectedCards.push(card.id);
                }
                self.renderMeldPhase();
            });

            $('#canasta-create-meld').on('click', function() {
                if (self.selectedCards.length >= 3 && self.onMove) {
                    self.onMove({
                        action: 'create_meld',
                        cards: self.selectedCards
                    });
                    self.selectedCards = [];
                }
            });

            $('#canasta-add-to-meld').on('click', function() {
                if (self.selectedCards.length >= 1 && self.onMove) {
                    // Simplified - would show meld selection UI
                    self.onMove({
                        action: 'add_to_meld',
                        cards: self.selectedCards,
                        meld_index: 0 // Would be selected by player
                    });
                    self.selectedCards = [];
                }
            });

            $('#canasta-skip-meld').on('click', function() {
                if (self.onMove) {
                    self.onMove({ action: 'skip_meld' });
                }
                self.selectedCards = [];
            });
        },

        bindDiscardEvents: function() {
            const self = this;

            CGACards.bindCardClicks('.my-hand-section', (card) => {
                if (self.onMove) {
                    self.onMove({
                        action: 'discard',
                        card_id: card.id
                    });
                }
            });
        },

        renderHandEnd: function() {
            let html = '<div class="canasta-hand-end">';
            html += '<h2>Hand Complete!</h2>';

            html += '<div class="hand-scores">';
            html += '<h4>Hand Scores</h4>';

            for (let seat = 0; seat < this.state.players.length; seat++) {
                const player = this.state.players[seat];
                const score = this.state.hand_scores[seat];
                html += `<p>${player.name}: ${score} points</p>`;
            }

            html += '</div>';

            if (this.state.has_teams) {
                html += '<div class="total-scores">';
                html += '<h4>Total Scores</h4>';
                html += `<p>Team 1: ${this.state.team_scores[0]}</p>`;
                html += `<p>Team 2: ${this.state.team_scores[1]}</p>`;
                html += '</div>';
            }

            html += '<button id="canasta-next-hand" class="cga-btn cga-btn-primary">Next Hand</button>';
            html += '</div>';

            $('#cga-game-board').html(html);

            $('#canasta-next-hand').on('click', () => {
                if (this.onMove) {
                    this.onMove({ action: 'next_hand' });
                }
            });
        },

        getSuitSymbol: function(suit) {
            const symbols = {
                'clubs': '♣',
                'diamonds': '♦',
                'hearts': '♥',
                'spades': '♠'
            };
            return symbols[suit] || '';
        }
    };

    window.CGAGames = window.CGAGames || {};
    window.CGAGames.canasta = CanastaRenderer;

})(jQuery);
