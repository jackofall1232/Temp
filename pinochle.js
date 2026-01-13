/**
 * Pinochle Game Renderer
 */
(function($) {
    'use strict';

    const SUIT_SYMBOLS = { hearts: '&hearts;', diamonds: '&diams;', clubs: '&clubs;', spades: '&spades;' };

    const PinochleRenderer = {
        mySeat: null,
        onMove: null,
        state: null,

        render: function(stateData, mySeat, onMove) {
            this.mySeat = mySeat;
            this.onMove = onMove;
            this.state = stateData.state;

            const phase = this.state.phase;

            if (phase === 'bidding') {
                this.renderBiddingPhase();
            } else if (phase === 'trump_selection') {
                this.renderTrumpSelection();
            } else if (phase === 'playing') {
                this.renderPlayingPhase();
            } else if (phase === 'round_end') {
                this.renderRoundEnd();
            }
        },

        renderBiddingPhase: function() {
            const isMyTurn = this.state.current_turn === this.mySeat;
            const myHand = this.state.hands[this.mySeat];
            const highBid = this.state.high_bid;
            const highBidder = this.state.high_bidder;

            let html = '<div class="pinochle-bidding">';
            html += '<h3>Bidding</h3>';

            // Current bid status
            html += '<div class="bid-status">';
            if (highBidder !== null) {
                html += `<p>High bid: <strong>${highBid}</strong> by ${this.state.players[highBidder].name}</p>`;
            } else {
                html += `<p>Minimum bid: ${20}</p>`;
            }
            html += '</div>';

            // Players status
            html += '<div class="players-bid-status">';
            for (let seat = 0; seat < 4; seat++) {
                const player = this.state.players[seat];
                const passed = this.state.passed[seat];
                const isActive = this.state.current_turn === seat;
                const statusClass = passed ? 'passed' : (isActive ? 'active' : '');

                html += `<div class="player-bid-status ${statusClass}">
                    ${player.name}: ${passed ? 'Passed' : (isActive ? 'Bidding...' : 'Active')}
                </div>`;
            }
            html += '</div>';

            // My hand
            html += '<div class="my-hand-preview">';
            html += CGACards.renderHand(myHand, { validCards: [] });
            html += '</div>';

            // Bid buttons
            if (isMyTurn && !this.state.passed[this.mySeat]) {
                html += '<div class="cga-bid-area">';
                html += '<div class="cga-bid-buttons">';
                html += '<button class="cga-bid-btn pass" data-action="pass">Pass</button>';

                for (let bid = highBid + 1; bid <= Math.min(highBid + 10, 50); bid++) {
                    html += `<button class="cga-bid-btn" data-action="bid" data-bid="${bid}">${bid}</button>`;
                }
                html += '</div></div>';
            }

            html += '</div>';
            html += this.renderScoreboard();

            $('#cga-game-board').html(html);

            if (isMyTurn) {
                this.bindBidEvents();
            }
        },

        bindBidEvents: function() {
            const self = this;

            $('.cga-bid-btn').on('click', function() {
                const action = $(this).data('action');
                const move = { action };

                if (action === 'bid') {
                    move.bid = $(this).data('bid');
                }

                if (self.onMove) {
                    self.onMove(move);
                }
            });
        },

        renderTrumpSelection: function() {
            const isMyTurn = this.state.current_turn === this.mySeat;
            const myHand = this.state.hands[this.mySeat];

            let html = '<div class="pinochle-trump">';
            html += '<h3>Select Trump</h3>';
            html += `<p>${this.state.players[this.state.high_bidder].name} won the bid at ${this.state.high_bid}</p>`;

            html += '<div class="my-hand-preview">';
            html += CGACards.renderHand(myHand, { validCards: [] });
            html += '</div>';

            if (isMyTurn) {
                html += '<div class="trump-selection">';
                html += '<p>Choose trump suit:</p>';
                html += '<div class="suit-buttons">';
                for (const suit of ['hearts', 'diamonds', 'clubs', 'spades']) {
                    const color = (suit === 'hearts' || suit === 'diamonds') ? 'red' : 'black';
                    html += `<button class="cga-btn suit-btn ${color}" data-suit="${suit}">
                        ${SUIT_SYMBOLS[suit]} ${suit.charAt(0).toUpperCase() + suit.slice(1)}
                    </button>`;
                }
                html += '</div></div>';
            }

            html += '</div>';

            $('#cga-game-board').html(html);

            if (isMyTurn) {
                this.bindTrumpEvents();
            }
        },

        bindTrumpEvents: function() {
            const self = this;

            $('.suit-btn').on('click', function() {
                const suit = $(this).data('suit');
                if (self.onMove) {
                    self.onMove({ suit });
                }
            });
        },

        renderPlayingPhase: function() {
            const currentTurn = this.state.current_turn;
            const isMyTurn = currentTurn === this.mySeat;
            const myHand = this.state.hands[this.mySeat];

            let html = '<div class="pinochle-table cga-table">';

            // Player positions
            const positions = this.getPositions();
            positions.forEach(pos => {
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
            html += '</div>';
            html += '</div>';

            // My hand
            html += '<div class="my-hand-section">';
            const validMoves = isMyTurn ? this.getValidCardIds() : [];
            html += CGACards.renderHand(myHand, { validCards: validMoves });
            html += '</div>';

            // Meld display
            html += this.renderMelds();
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
            const meld = this.state.melds[seat];

            let html = CGACards.renderPlayerInfo(player, {
                isActive: isActive,
                showScore: false,
                extras: `<div class="meld-points">Meld: ${meld}</div>`
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
            const trump = this.state.trump;

            if (trick.length === 0) {
                return myHand.map(c => c.id);
            }

            const leadSuit = trick[0].card.suit;
            const suitCards = myHand.filter(c => c.suit === leadSuit);

            if (suitCards.length > 0) {
                return suitCards.map(c => c.id);
            }

            // Must trump if possible
            const trumpCards = myHand.filter(c => c.suit === trump);
            if (trumpCards.length > 0) {
                return trumpCards.map(c => c.id);
            }

            return myHand.map(c => c.id);
        },

        bindPlayEvents: function() {
            const self = this;
            CGACards.bindCardClicks('.my-hand-section', (card) => {
                if (self.onMove) {
                    self.onMove({ card_id: card.id });
                }
            });
        },

        renderMelds: function() {
            const myTeam = this.mySeat % 2;
            const teams = this.state.teams;
            const melds = this.state.melds;
            const counters = this.state.counters;

            let html = '<div class="pinochle-melds">';
            html += '<table>';
            html += '<tr><th>Team</th><th>Meld</th><th>Counters</th><th>Total</th></tr>';

            for (let t = 0; t < 2; t++) {
                const teamMeld = melds[teams[t][0]] + melds[teams[t][1]];
                const teamCounters = counters[t];
                const teamPlayers = teams[t].map(s => this.state.players[s].name).join(' & ');
                const isMyTeam = t === myTeam;

                html += `<tr class="${isMyTeam ? 'my-team' : ''}">
                    <td>${teamPlayers}</td>
                    <td>${teamMeld}</td>
                    <td>${teamCounters}</td>
                    <td>${teamMeld + teamCounters}</td>
                </tr>`;
            }

            html += '</table></div>';
            return html;
        },

        renderScoreboard: function() {
            const myTeam = this.mySeat % 2;
            const teams = this.state.teams;
            const scores = this.state.team_scores;

            let html = '<div class="pinochle-scoreboard cga-scoreboard">';
            html += '<table>';
            html += '<tr><th>Team</th><th>Score</th></tr>';

            for (let t = 0; t < 2; t++) {
                const teamPlayers = teams[t].map(s => this.state.players[s].name).join(' & ');
                const isMyTeam = t === myTeam;
                html += `<tr class="${isMyTeam ? 'my-team' : ''}">
                    <td>${teamPlayers}</td>
                    <td>${scores[t]} / 150</td>
                </tr>`;
            }

            html += '</table></div>';
            return html;
        },

        renderRoundEnd: function() {
            let html = '<div class="pinochle-round-end">';
            html += '<h2>Round Complete!</h2>';
            html += this.renderMelds();
            html += this.renderScoreboard();
            html += '<button id="pinochle-next-round" class="cga-btn cga-btn-primary">Next Round</button>';
            html += '</div>';

            $('#cga-game-board').html(html);

            // Bind next round button
            $('#pinochle-next-round').on('click', () => {
                if (this.onMove) {
                    this.onMove({ action: 'next_round' });
                }
            });
        }
    };

    window.CGAGames = window.CGAGames || {};
    window.CGAGames.pinochle = PinochleRenderer;

})(jQuery);
