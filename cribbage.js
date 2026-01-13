/**
 * Cribbage Game Renderer
 */
(function($) {
    'use strict';

    const CribbageRenderer = {
        mySeat: null,
        onMove: null,
        state: null,
        selectedCards: [],

        render: function(stateData, mySeat, onMove) {
            this.mySeat = mySeat;
            this.onMove = onMove;
            this.state = stateData.state;

            const phase = this.state.phase;

            if (phase === 'discard') {
                this.renderDiscardPhase();
            } else if (phase === 'pegging') {
                this.renderPeggingPhase();
            } else if (phase === 'round_end') {
                this.renderRoundEnd();
            }
        },

        renderDiscardPhase: function() {
            const myHand = this.state.hands[this.mySeat];
            const hasDiscarded = this.state.discards[this.mySeat].length === 2;
            const isDealer = this.state.dealer === this.mySeat;

            let html = '<div class="cribbage-discard">';
            html += '<h3>Discard to Crib</h3>';
            html += `<p>Select 2 cards to put in ${isDealer ? 'your' : "opponent's"} crib.</p>`;

            if (hasDiscarded) {
                html += '<p class="waiting">Waiting for opponent to discard...</p>';
                html += CGACards.renderHand(myHand, { validCards: [] });
            } else {
                html += CGACards.renderHand(myHand, {
                    selectedCards: this.selectedCards,
                    validCards: myHand.map(c => c.id)
                });

                html += `<button id="crib-confirm" class="cga-btn cga-btn-primary" 
                         ${this.selectedCards.length !== 2 ? 'disabled' : ''}>
                    Discard (${this.selectedCards.length}/2)
                </button>`;
            }

            html += '</div>';
            html += this.renderScoreboard();

            $('#cga-game-board').html(html);

            if (!hasDiscarded) {
                this.bindDiscardEvents();
            }
        },

        bindDiscardEvents: function() {
            const self = this;

            CGACards.bindCardClicks('#cga-game-board', (card) => {
                const idx = self.selectedCards.indexOf(card.id);
                if (idx >= 0) {
                    self.selectedCards.splice(idx, 1);
                } else if (self.selectedCards.length < 2) {
                    self.selectedCards.push(card.id);
                }
                self.renderDiscardPhase();
            });

            $('#crib-confirm').on('click', function() {
                if (self.selectedCards.length === 2 && self.onMove) {
                    self.onMove({ cards: self.selectedCards });
                    self.selectedCards = [];
                }
            });
        },

        renderPeggingPhase: function() {
            const isMyTurn = this.state.current_turn === this.mySeat;
            const myHand = this.state.peg_hands[this.mySeat];
            const oppHandCount = this.state.peg_hands[(this.mySeat + 1) % 2];
            const starter = this.state.starter;

            let html = '<div class="cribbage-pegging">';

            // Starter card
            html += '<div class="starter-area">';
            html += '<p>Starter:</p>';
            html += CGACards.renderCard(starter);
            html += '</div>';

            // Peg count
            html += `<div class="peg-count">Count: <strong>${this.state.peg_count}</strong> / 31</div>`;

            // Peg pile
            html += '<div class="peg-pile">';
            if (this.state.peg_pile.length > 0) {
                this.state.peg_pile.forEach(play => {
                    html += CGACards.renderCard(play.card);
                });
            } else {
                html += '<span class="empty-pile">Play cards here</span>';
            }
            html += '</div>';

            // Opponent's hand
            html += '<div class="opponent-area">';
            html += CGACards.renderPlayerInfo(this.state.players[(this.mySeat + 1) % 2], { showScore: false });
            html += CGACards.renderOpponentHand(oppHandCount);
            html += '</div>';

            // My hand
            html += '<div class="my-hand-section">';
            
            if (isMyTurn) {
                const playable = this.getPlayableCardIds();
                
                if (playable.length === 0) {
                    html += '<p>No playable cards!</p>';
                    html += CGACards.renderHand(myHand, { validCards: [] });
                    html += '<button id="say-go" class="cga-btn">Say "Go"</button>';
                } else {
                    html += CGACards.renderHand(myHand, { validCards: playable });
                }
            } else {
                html += CGACards.renderHand(myHand, { validCards: [] });
                html += '<p class="waiting">Opponent\'s turn...</p>';
            }
            
            html += '</div>';
            html += '</div>';

            html += this.renderScoreboard();

            $('#cga-game-board').html(html);

            if (isMyTurn) {
                this.bindPeggingEvents();
            }
        },

        getPlayableCardIds: function() {
            const myHand = this.state.peg_hands[this.mySeat];
            const pegCount = this.state.peg_count;
            const pegValues = { 'A': 1, '2': 2, '3': 3, '4': 4, '5': 5, '6': 6, '7': 7, '8': 8, '9': 9, '10': 10, 'J': 10, 'Q': 10, 'K': 10 };

            return myHand
                .filter(c => pegCount + pegValues[c.rank] <= 31)
                .map(c => c.id);
        },

        bindPeggingEvents: function() {
            const self = this;

            CGACards.bindCardClicks('.my-hand-section', (card) => {
                if (self.onMove) {
                    self.onMove({ card_id: card.id });
                }
            });

            $('#say-go').on('click', function() {
                if (self.onMove) {
                    self.onMove({ action: 'go' });
                }
            });
        },

        renderScoreboard: function() {
            const scores = this.state.scores;
            
            let html = '<div class="cribbage-scoreboard">';
            html += '<div class="cribbage-board">';
            
            for (let seat = 0; seat < 2; seat++) {
                const player = this.state.players[seat];
                const score = scores[seat];
                const isMe = seat === this.mySeat;
                const isDealer = seat === this.state.dealer;
                
                html += `<div class="player-score ${isMe ? 'my-score' : ''}">
                    <span class="name">${player.name}${isDealer ? ' (Dealer)' : ''}</span>
                    <span class="score">${score} / 121</span>
                    <div class="score-bar">
                        <div class="score-fill" style="width: ${(score / 121) * 100}%"></div>
                    </div>
                </div>`;
            }
            
            html += '</div></div>';
            return html;
        },

        renderRoundEnd: function() {
            let html = '<div class="cribbage-round-end">';
            html += '<h2>Round Complete!</h2>';
            html += this.renderScoreboard();
            html += '<button id="cribbage-next-round" class="cga-btn cga-btn-primary">Next Round</button>';
            html += '</div>';

            $('#cga-game-board').html(html);

            // Bind next round button
            const self = this;
            $('#cribbage-next-round').on('click', function() {
                if (self.onMove) {
                    self.onMove({ action: 'next_round' });
                }
            });
        }
    };

    window.CGAGames = window.CGAGames || {};
    window.CGAGames.cribbage = CribbageRenderer;

})(jQuery);
