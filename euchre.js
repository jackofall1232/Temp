/**
 * Euchre Game Renderer
 */
(function($) {
    'use strict';

    const SUIT_SYMBOLS = { hearts: '&hearts;', diamonds: '&diams;', clubs: '&clubs;', spades: '&spades;' };

    const EuchreRenderer = {
        mySeat: null,
        onMove: null,
        state: null,

        render: function(stateData, mySeat, onMove) {
            this.mySeat = mySeat;
            this.onMove = onMove;
            this.state = stateData.state;

            const phase = this.state.phase;

            if (phase === 'calling_round1' || phase === 'calling_round2') {
                this.renderCallingPhase();
            } else if (phase === 'dealer_discard') {
                this.renderDiscardPhase();
            } else if (phase === 'playing') {
                this.renderPlayingPhase();
            } else if (phase === 'round_end') {
                this.renderRoundEnd();
            }
        },

        renderCallingPhase: function() {
            const isMyTurn = this.state.current_turn === this.mySeat;
            const myHand = this.state.hands[this.mySeat];
            const turnedCard = this.state.turned_card;
            const isRound1 = this.state.phase === 'calling_round1';

            let html = '<div class="euchre-calling">';
            html += `<h3>${isRound1 ? 'First Round' : 'Second Round'} - Call Trump</h3>`;

            // Show turned card
            html += '<div class="turned-card-area">';
            html += '<p>Turned card:</p>';
            html += CGACards.renderCard(turnedCard);
            html += '</div>';

            // My hand
            html += '<div class="my-hand-preview">';
            html += CGACards.renderHand(myHand, { validCards: [] });
            html += '</div>';

            // Action buttons
            if (isMyTurn) {
                html += '<div class="call-actions">';

                if (isRound1) {
                    html += `<button class="cga-btn" data-action="order_up">Order Up (${SUIT_SYMBOLS[turnedCard.suit]})</button>`;
                    html += `<button class="cga-btn" data-action="order_up_alone">Order Up Alone</button>`;
                    html += '<button class="cga-btn pass" data-action="pass">Pass</button>';
                } else {
                    const turnedSuit = turnedCard.suit;
                    html += '<p>Call a suit:</p>';
                    html += '<div class="suit-buttons">';
                    for (const suit of ['hearts', 'diamonds', 'clubs', 'spades']) {
                        if (suit !== turnedSuit) {
                            const color = (suit === 'hearts' || suit === 'diamonds') ? 'red' : 'black';
                            html += `<button class="cga-btn suit-btn ${color}" data-action="call" data-suit="${suit}">${SUIT_SYMBOLS[suit]}</button>`;
                        }
                    }
                    html += '</div>';

                    if (this.mySeat !== this.state.dealer) {
                        html += '<button class="cga-btn pass" data-action="pass">Pass</button>';
                    }
                }

                html += '</div>';
            } else {
                html += `<p class="waiting">Waiting for ${this.state.players[this.state.current_turn].name}...</p>`;
            }

            html += '</div>';

            // Scoreboard
            html += this.renderScoreboard();

            $('#cga-game-board').html(html);

            if (isMyTurn) {
                this.bindCallEvents();
            }
        },

        bindCallEvents: function() {
            const self = this;

            $('[data-action]').on('click', function() {
                const action = $(this).data('action');
                const suit = $(this).data('suit');

                const move = { action };
                if (suit) move.suit = suit;

                if (self.onMove) {
                    self.onMove(move);
                }
            });
        },

        renderDiscardPhase: function() {
            const myHand = this.state.hands[this.mySeat];
            const isMyTurn = this.state.current_turn === this.mySeat;

            let html = '<div class="euchre-discard">';
            html += '<h3>Dealer Discard</h3>';
            html += `<p>Trump is ${SUIT_SYMBOLS[this.state.trump]}. Select a card to discard.</p>`;

            html += '<div class="my-hand-section">';
            html += CGACards.renderHand(myHand, {
                validCards: isMyTurn ? myHand.map(c => c.id) : []
            });
            html += '</div>';

            html += '</div>';

            $('#cga-game-board').html(html);

            if (isMyTurn) {
                this.bindDiscardEvents();
            }
        },

        bindDiscardEvents: function() {
            const self = this;
            CGACards.bindCardClicks('.my-hand-section', (card) => {
                if (self.onMove) {
                    self.onMove({ card_id: card.id });
                }
            });
        },

        renderPlayingPhase: function() {
            const currentTurn = this.state.current_turn;
            const isMyTurn = currentTurn === this.mySeat;
            const myHand = this.state.hands[this.mySeat];

            let html = '<div class="euchre-table cga-table">';

            // Player positions
            const positions = this.getPositions();
            positions.forEach(pos => {
                // Skip partner if going alone
                if (this.state.going_alone) {
                    const callerPartner = (this.state.caller + 2) % 4;
                    if (pos.seat === callerPartner) return;
                }

                html += `<div class="cga-seat-${pos.position}">`;
                html += this.renderPlayerArea(pos.seat, pos.position);
                html += '</div>';
            });

            // Center
            html += '<div class="cga-table-center">';
            html += '<div class="cga-play-area">';

            if (this.state.trick.length > 0) {
                html += CGACards.renderTrick(this.state.trick, this.state.players);
            }

            html += '</div>';
            html += CGACards.renderTrumpIndicator(this.state.trump);

            if (this.state.going_alone) {
                html += `<div class="going-alone-indicator">${this.state.players[this.state.caller].name} going alone!</div>`;
            }

            html += '</div>';
            html += '</div>';

            // My hand
            html += '<div class="my-hand-section">';
            const validMoves = isMyTurn ? this.getValidCardIds() : [];
            html += CGACards.renderHand(myHand, {
                validCards: validMoves
            });
            html += '</div>';

            html += this.renderScoreboard();

            $('#cga-game-board').html(html);

            if (isMyTurn) {
                this.bindPlayEvents();
            }
        },

        getPositions: function() {
            const positions = ['bottom', 'left', 'top', 'right'];
            const result = [];
            for (let i = 0; i < 4; i++) {
                result.push({
                    seat: (this.mySeat + i) % 4,
                    position: positions[i]
                });
            }
            return result;
        },

        renderPlayerArea: function(seat, position) {
            const player = this.state.players[seat];
            const isActive = this.state.current_turn === seat;
            const isMe = seat === this.mySeat;
            const cardCount = isMe ? 0 : this.state.hands[seat];
            const tricks = this.state.tricks_won[seat];
            const isCaller = seat === this.state.caller;

            let html = CGACards.renderPlayerInfo(player, {
                isActive: isActive,
                showScore: false,
                extras: `<div class="tricks">Tricks: ${tricks}${isCaller ? ' (Maker)' : ''}</div>`
            });

            if (!isMe && cardCount > 0) {
                const vertical = position === 'left' || position === 'right';
                html += CGACards.renderOpponentHand(cardCount, { vertical });
            }

            return html;
        },

        getValidCardIds: function() {
            const myHand = this.state.hands[this.mySeat];
            const trick = this.state.trick;

            if (trick.length === 0) {
                return myHand.map(c => c.id);
            }

            const trump = this.state.trump;
            const leadCard = trick[0].card;
            const leadSuit = this.getEffectiveSuit(leadCard, trump);

            const suitCards = myHand.filter(c => this.getEffectiveSuit(c, trump) === leadSuit);

            if (suitCards.length > 0) {
                return suitCards.map(c => c.id);
            }

            return myHand.map(c => c.id);
        },

        getEffectiveSuit: function(card, trump) {
            if (!trump) return card.suit;

            if (card.rank === 'J') {
                const sameColor = {
                    hearts: 'diamonds', diamonds: 'hearts',
                    clubs: 'spades', spades: 'clubs'
                };
                if (card.suit === sameColor[trump]) {
                    return trump;
                }
            }

            return card.suit;
        },

        bindPlayEvents: function() {
            const self = this;
            CGACards.bindCardClicks('.my-hand-section', (card) => {
                if (self.onMove) {
                    self.onMove({ card_id: card.id });
                }
            });
        },

        renderScoreboard: function() {
            const myTeam = this.mySeat % 2;
            const teams = this.state.teams;
            const scores = this.state.team_scores;

            let html = '<div class="euchre-scoreboard cga-scoreboard">';
            html += '<table>';
            html += '<tr><th>Team</th><th>Score</th></tr>';

            for (let t = 0; t < 2; t++) {
                const teamPlayers = teams[t].map(s => this.state.players[s].name).join(' & ');
                const isMyTeam = t === myTeam;
                html += `<tr class="${isMyTeam ? 'my-team' : ''}">
                    <td>${teamPlayers}</td>
                    <td>${scores[t]} / 10</td>
                </tr>`;
            }

            html += '</table></div>';
            return html;
        },

        renderRoundEnd: function() {
            let html = '<div class="euchre-round-end">';
            html += '<h2>Round Complete!</h2>';
            html += this.renderScoreboard();
            html += '<button id="euchre-next-round" class="cga-btn cga-btn-primary">Next Round</button>';
            html += '</div>';

            $('#cga-game-board').html(html);

            // Bind next round button
            $('#euchre-next-round').on('click', () => {
                if (this.onMove) {
                    this.onMove({ action: 'next_round' });
                }
            });
        }
    };

    window.CGAGames = window.CGAGames || {};
    window.CGAGames.euchre = EuchreRenderer;

})(jQuery);
