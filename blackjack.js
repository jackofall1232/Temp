/**
 * Blackjack (21) Game Renderer
 * Casino card game - beat the dealer without going over 21
 */
(function($) {
    'use strict';

    const BlackjackRenderer = {
        mySeat: null,
        onMove: null,
        state: null,

        render: function(stateData, mySeat, onMove) {
            this.mySeat = mySeat;
            this.onMove = onMove;
            this.state = stateData.state;

            if (this.state.phase === 'betting') {
                this.renderBettingPhase();
            } else if (this.state.phase === 'player_actions') {
                this.renderPlayerActionsPhase();
            } else if (this.state.phase === 'dealer_play') {
                this.renderDealerPlayPhase();
            } else if (this.state.phase === 'payout') {
                this.renderPayoutPhase();
            }
        },

        renderBettingPhase: function() {
            const myPlayer = this.state.players[this.mySeat];
            const hasPlacedBet = this.state.bets[this.mySeat] > 0;

            let html = '<div class="blackjack-betting">';
            html += '<h3>Place Your Bet</h3>';

            html += '<div class="player-chips">';
            html += `<p>Your Chips: $${myPlayer.chips}</p>`;
            if (hasPlacedBet) {
                html += `<p>Current Bet: $${this.state.bets[this.mySeat]}</p>`;
            }
            html += '</div>';

            if (!hasPlacedBet) {
                html += '<div class="betting-controls">';
                html += '<p>Select bet amount:</p>';

                const denominations = [5, 10, 25, 50, 100];
                denominations.forEach(amount => {
                    const disabled = amount > myPlayer.chips;
                    html += `<button class="chip-btn" data-amount="${amount}" ${disabled ? 'disabled' : ''}>`;
                    html += `$${amount}`;
                    html += '</button>';
                });

                html += '<div class="bet-input-group">';
                html += '<input type="number" id="custom-bet" min="1" max="' + myPlayer.chips + '" placeholder="Custom amount">';
                html += '<button id="place-custom-bet" class="cga-btn cga-btn-secondary">Place Bet</button>';
                html += '</div>';

                html += '</div>';
            } else {
                html += '<p>Waiting for other players...</p>';
                html += '<div class="other-players-status">';

                for (let seat = 0; seat < this.state.players.length; seat++) {
                    if (seat === this.mySeat) continue;
                    const player = this.state.players[seat];
                    const status = this.state.bets[seat] > 0 ? '✓ Ready' : '⏳ Betting';
                    html += `<p>${player.name}: ${status}</p>`;
                }

                html += '</div>';
            }

            html += '</div>';

            $('#cga-game-board').html(html);

            if (!hasPlacedBet) {
                this.bindBettingEvents();
            }
        },

        renderPlayerActionsPhase: function() {
            const currentPlayer = this.state.current_player;
            const isMyTurn = currentPlayer === this.mySeat;
            const myHand = this.state.hands[this.mySeat];
            const myHandValue = this.calculateHandValue(myHand);

            let html = '<div class="blackjack-game">';

            // Dealer's hand (one card hidden until dealer plays)
            html += '<div class="dealer-area">';
            html += '<h4>Dealer</h4>';
            html += '<div class="dealer-hand">';

            const dealerHand = this.state.dealer_hand;
            dealerHand.forEach((card, idx) => {
                if (idx === 1) {
                    // Second card face down
                    html += CGACards.renderCard({ id: 'hidden', rank: '?', suit: '?' }, { faceUp: false });
                } else {
                    html += CGACards.renderCard(card, { faceUp: true });
                }
            });

            html += '</div>';
            html += `<p class="hand-value">Showing: ${this.calculateSingleCardValue(dealerHand[0])}</p>`;
            html += '</div>';

            // All players
            html += '<div class="players-area">';

            for (let seat = 0; seat < this.state.players.length; seat++) {
                const player = this.state.players[seat];
                const isMe = seat === this.mySeat;
                const isActive = seat === currentPlayer;
                const hand = this.state.hands[seat];
                const handValue = this.calculateHandValue(hand);
                const status = this.state.player_status[seat];

                html += `<div class="player-box ${isActive ? 'active' : ''} ${isMe ? 'my-box' : ''}">`;
                html += `<h4>${player.name}${isMe ? ' (You)' : ''}</h4>`;
                html += `<p>Bet: $${this.state.bets[seat]}</p>`;

                html += '<div class="player-hand">';
                if (isMe || status !== 'playing') {
                    // Show my cards or finished players' cards
                    hand.forEach(card => {
                        html += CGACards.renderCard(card, { faceUp: true, compact: true });
                    });
                } else {
                    // Show card backs for other active players
                    hand.forEach(() => {
                        html += CGACards.renderCard({ id: 'hidden', rank: '?', suit: '?' }, { faceUp: false, compact: true });
                    });
                }
                html += '</div>';

                html += `<p class="hand-value">`;
                if (isMe || status !== 'playing') {
                    html += `Value: ${handValue}`;
                }
                if (status === 'bust') {
                    html += ' <span class="bust-indicator">BUST!</span>';
                } else if (status === 'blackjack') {
                    html += ' <span class="blackjack-indicator">BLACKJACK!</span>';
                } else if (status === 'stand') {
                    html += ' <span class="stand-indicator">Stand</span>';
                }
                html += '</p>';

                html += '</div>';
            }

            html += '</div>';

            // Action buttons if my turn
            if (isMyTurn && this.state.player_status[this.mySeat] === 'playing') {
                html += '<div class="action-buttons">';
                html += '<h3>Your Turn</h3>';

                html += '<button id="action-hit" class="cga-btn cga-btn-primary">Hit</button>';
                html += '<button id="action-stand" class="cga-btn cga-btn-primary">Stand</button>';

                // Double down only on first action with 2 cards
                if (myHand.length === 2) {
                    const canDouble = this.state.players[this.mySeat].chips >= this.state.bets[this.mySeat];
                    html += `<button id="action-double" class="cga-btn cga-btn-secondary" ${canDouble ? '' : 'disabled'}>Double Down</button>`;
                }

                // Split only if first 2 cards same rank
                if (myHand.length === 2 && myHand[0].rank === myHand[1].rank) {
                    const canSplit = this.state.players[this.mySeat].chips >= this.state.bets[this.mySeat];
                    html += `<button id="action-split" class="cga-btn cga-btn-secondary" ${canSplit ? '' : 'disabled'}>Split</button>`;
                }

                html += '</div>';
            }

            html += '</div>';

            $('#cga-game-board').html(html);

            if (isMyTurn) {
                this.bindActionEvents();
            }
        },

        renderDealerPlayPhase: function() {
            let html = '<div class="blackjack-dealer-turn">';
            html += '<h3>Dealer\'s Turn</h3>';

            // Show dealer's full hand
            html += '<div class="dealer-area-full">';
            html += '<h4>Dealer</h4>';
            html += '<div class="dealer-hand">';

            this.state.dealer_hand.forEach(card => {
                html += CGACards.renderCard(card, { faceUp: true });
            });

            html += '</div>';

            const dealerValue = this.calculateHandValue(this.state.dealer_hand);
            html += `<p class="hand-value">Value: ${dealerValue}</p>`;

            if (dealerValue > 21) {
                html += '<p class="bust-indicator">Dealer Busts!</p>';
            }

            html += '</div>';

            // Show all players for reference
            html += '<div class="players-summary">';
            for (let seat = 0; seat < this.state.players.length; seat++) {
                const player = this.state.players[seat];
                const handValue = this.calculateHandValue(this.state.hands[seat]);
                const status = this.state.player_status[seat];

                html += `<p>${player.name}: ${handValue} (${status})</p>`;
            }
            html += '</div>';

            html += '</div>';

            $('#cga-game-board').html(html);
        },

        renderPayoutPhase: function() {
            let html = '<div class="blackjack-payout">';
            html += '<h2>Round Complete!</h2>';

            const dealerValue = this.calculateHandValue(this.state.dealer_hand);
            html += `<p class="dealer-final">Dealer: ${dealerValue}${dealerValue > 21 ? ' (Bust)' : ''}</p>`;

            html += '<div class="results-table">';
            html += '<table>';
            html += '<tr><th>Player</th><th>Hand</th><th>Result</th><th>Payout</th><th>Chips</th></tr>';

            for (let seat = 0; seat < this.state.players.length; seat++) {
                const player = this.state.players[seat];
                const handValue = this.calculateHandValue(this.state.hands[seat]);
                const result = this.state.results[seat];
                const payout = this.state.payouts[seat];
                const chips = this.state.players[seat].chips;

                const resultClass = result === 'win' ? 'win' : result === 'lose' ? 'lose' : 'push';

                html += `<tr class="${resultClass}">`;
                html += `<td>${player.name}</td>`;
                html += `<td>${handValue}</td>`;
                html += `<td class="result-${resultClass}">${result.toUpperCase()}</td>`;
                html += `<td>${payout > 0 ? '+' : ''}$${payout}</td>`;
                html += `<td>$${chips}</td>`;
                html += '</tr>';
            }

            html += '</table>';
            html += '</div>';

            html += '<button id="next-round" class="cga-btn cga-btn-primary">Next Round</button>';
            html += '</div>';

            $('#cga-game-board').html(html);

            $('#next-round').on('click', () => {
                if (this.onMove) {
                    this.onMove({ action: 'next_round' });
                }
            });
        },

        bindBettingEvents: function() {
            const self = this;

            $('.chip-btn').on('click', function() {
                const amount = parseInt($(this).data('amount'));
                if (self.onMove) {
                    self.onMove({
                        action: 'place_bet',
                        amount: amount
                    });
                }
            });

            $('#place-custom-bet').on('click', function() {
                const amount = parseInt($('#custom-bet').val());
                if (amount > 0 && self.onMove) {
                    self.onMove({
                        action: 'place_bet',
                        amount: amount
                    });
                }
            });
        },

        bindActionEvents: function() {
            const self = this;

            $('#action-hit').on('click', function() {
                if (self.onMove) {
                    self.onMove({ action: 'hit' });
                }
            });

            $('#action-stand').on('click', function() {
                if (self.onMove) {
                    self.onMove({ action: 'stand' });
                }
            });

            $('#action-double').on('click', function() {
                if (self.onMove) {
                    self.onMove({ action: 'double' });
                }
            });

            $('#action-split').on('click', function() {
                if (self.onMove) {
                    self.onMove({ action: 'split' });
                }
            });
        },

        calculateHandValue: function(hand) {
            let value = 0;
            let aces = 0;

            hand.forEach(card => {
                if (card.rank === 'A') {
                    aces++;
                    value += 11;
                } else if (['K', 'Q', 'J'].includes(card.rank)) {
                    value += 10;
                } else {
                    value += parseInt(card.rank);
                }
            });

            // Adjust for aces
            while (value > 21 && aces > 0) {
                value -= 10;
                aces--;
            }

            return value;
        },

        calculateSingleCardValue: function(card) {
            if (card.rank === 'A') return '11 or 1';
            if (['K', 'Q', 'J'].includes(card.rank)) return '10';
            return card.rank;
        }
    };

    window.CGAGames = window.CGAGames || {};
    window.CGAGames.blackjack = BlackjackRenderer;

})(jQuery);
