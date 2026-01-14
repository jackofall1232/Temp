/**
 * Bridge Game Renderer
 * Partnership trick-taking game with bidding phase
 */
(function($) {
    'use strict';

    const BridgeRenderer = {
        mySeat: null,
        onMove: null,
        state: null,

        render: function(stateData, mySeat, onMove) {
            this.mySeat = mySeat;
            this.onMove = onMove;
            this.state = stateData.state;

            if (this.state.phase === 'bidding') {
                this.renderBiddingPhase();
            } else if (this.state.phase === 'playing') {
                this.renderPlayingPhase();
            } else if (this.state.phase === 'hand_end') {
                this.renderHandEnd();
            }
        },

        renderBiddingPhase: function() {
            const currentBidder = this.state.current_bidder;
            const isMyTurn = currentBidder === this.mySeat;
            const myHand = this.state.hands[this.mySeat];

            let html = '<div class="bridge-bidding">';
            html += '<h3>Bidding Phase</h3>';

            // Vulnerability display
            html += '<div class="vulnerability-status">';
            html += `<span>Vulnerability: ${this.formatVulnerability()}</span>`;
            html += '</div>';

            // Bidding history
            html += '<div class="bidding-box">';
            html += '<h4>Bidding History</h4>';
            html += '<div class="bid-history">';

            if (this.state.bidding_history.length === 0) {
                html += '<p>Dealer opens bidding...</p>';
            } else {
                html += '<table class="bid-table">';
                for (let i = 0; i < this.state.bidding_history.length; i++) {
                    const bid = this.state.bidding_history[i];
                    const player = this.state.players[bid.seat];
                    html += `<tr><td>${player.name}:</td><td class="bid-call">${this.formatBid(bid.bid)}</td></tr>`;
                }
                html += '</table>';
            }

            html += '</div>';

            // Current contract info
            if (this.state.current_contract) {
                html += `<div class="current-contract">Current: ${this.formatContract()}</div>`;
            }

            html += '</div>';

            // Bidding controls
            if (isMyTurn) {
                html += '<div class="bidding-controls">';
                html += '<h4>Your Bid</h4>';

                // Level buttons (1-7)
                html += '<div class="bid-levels">';
                for (let level = 1; level <= 7; level++) {
                    html += `<button class="bid-level-btn" data-level="${level}">${level}</button>`;
                }
                html += '</div>';

                // Suit buttons
                html += '<div class="bid-suits">';
                const suits = ['clubs', 'diamonds', 'hearts', 'spades', 'notrump'];
                suits.forEach(suit => {
                    html += `<button class="bid-suit-btn" data-suit="${suit}">${this.formatSuit(suit)}</button>`;
                });
                html += '</div>';

                // Special bids
                html += '<div class="special-bids">';
                html += '<button id="bid-pass" class="bid-special-btn">Pass</button>';
                html += '<button id="bid-double" class="bid-special-btn">Double</button>';
                html += '<button id="bid-redouble" class="bid-special-btn">Redouble</button>';
                html += '</div>';

                html += '</div>';
            } else {
                const bidder = this.state.players[currentBidder];
                html += `<p>Waiting for ${bidder.name} to bid...</p>`;
            }

            // Player's hand
            html += '<div class="my-hand-area">';
            html += '<h4>Your Hand</h4>';
            html += CGACards.renderHand(myHand, {
                selectedCards: [],
                validCards: [],
                sortBySuit: true
            });
            html += '</div>';

            html += '</div>';

            $('#cga-game-board').html(html);
            if (isMyTurn) {
                this.bindBiddingEvents();
            }
        },

        renderPlayingPhase: function() {
            const currentTurn = this.state.current_turn;
            const isMyTurn = currentTurn === this.mySeat;
            const myHand = this.state.hands[this.mySeat];
            const dummy = this.state.dummy_seat;

            let html = '<div class="bridge-table cga-table">';

            // Contract display
            html += '<div class="contract-display">';
            html += `<strong>Contract:</strong> ${this.formatContract()}`;
            html += ` | <strong>Declarer:</strong> ${this.state.players[this.state.declarer].name}`;
            html += ` | <strong>Tricks:</strong> N/S: ${this.state.tricks_ns} | E/W: ${this.state.tricks_ew}`;
            html += '</div>';

            // Render 4 player positions
            const positions = this.getPositions();

            positions.forEach(pos => {
                html += `<div class="cga-seat-${pos.position}">`;
                html += this.renderPlayerArea(pos.seat, pos.position, dummy);
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
            html += '</div>'; // center
            html += '</div>'; // table

            // Show dummy's hand if revealed
            if (dummy !== null && this.state.dummy_revealed) {
                html += '<div class="dummy-hand-section">';
                html += `<h4>Dummy (${this.state.players[dummy].name})</h4>`;
                const dummyHand = this.state.hands[dummy];
                html += CGACards.renderHand(dummyHand, {
                    selectedCards: [],
                    validCards: isMyTurn && currentTurn === dummy ? this.getValidCardIds() : [],
                    sortBySuit: true
                });
                html += '</div>';
            }

            // My hand at bottom
            if (this.mySeat !== dummy || !this.state.dummy_revealed) {
                html += '<div class="my-hand-section">';
                const validMoves = isMyTurn ? this.getValidCardIds() : [];
                html += CGACards.renderHand(myHand, {
                    selectedCards: [],
                    validCards: validMoves,
                    sortBySuit: true
                });
                html += '</div>';
            }

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

        renderPlayerArea: function(seat, position, dummySeat) {
            const player = this.state.players[seat];
            const isActive = this.state.current_turn === seat;
            const isMe = seat === this.mySeat;
            const isDummy = seat === dummySeat;
            const partnership = seat % 2 === 0 ? 'N/S' : 'E/W';

            let html = CGACards.renderPlayerInfo(player, {
                isActive: isActive,
                showScore: false,
                extras: `<div class="partnership">${partnership}${isDummy ? ' (Dummy)' : ''}</div>`
            });

            if (!isMe && (!isDummy || !this.state.dummy_revealed)) {
                const cardCount = this.state.hands[seat] ? this.state.hands[seat].length : 13;
                const vertical = position === 'left' || position === 'right';
                html += CGACards.renderOpponentHand(cardCount, { vertical });
            }

            return html;
        },

        getValidCardIds: function() {
            const myHand = this.state.hands[this.mySeat];
            const trick = this.state.trick;

            if (trick.length === 0) {
                // Leading - can play any card
                return myHand.map(c => c.id);
            }

            // Must follow suit if possible
            const leadSuit = trick[0].card.suit;
            const suitCards = myHand.filter(c => c.suit === leadSuit);

            if (suitCards.length > 0) {
                return suitCards.map(c => c.id);
            }

            // Can play anything if void in led suit
            return myHand.map(c => c.id);
        },

        bindBiddingEvents: function() {
            const self = this;
            let selectedLevel = null;
            let selectedSuit = null;

            $('.bid-level-btn').on('click', function() {
                $('.bid-level-btn').removeClass('selected');
                $(this).addClass('selected');
                selectedLevel = $(this).data('level');
            });

            $('.bid-suit-btn').on('click', function() {
                if (!selectedLevel) {
                    alert('Please select a level first');
                    return;
                }

                selectedSuit = $(this).data('suit');

                if (self.onMove) {
                    self.onMove({
                        bid_type: 'bid',
                        level: selectedLevel,
                        suit: selectedSuit
                    });
                }
            });

            $('#bid-pass').on('click', function() {
                if (self.onMove) {
                    self.onMove({ bid_type: 'pass' });
                }
            });

            $('#bid-double').on('click', function() {
                if (self.onMove) {
                    self.onMove({ bid_type: 'double' });
                }
            });

            $('#bid-redouble').on('click', function() {
                if (self.onMove) {
                    self.onMove({ bid_type: 'redouble' });
                }
            });
        },

        bindPlayingEvents: function() {
            const self = this;

            CGACards.bindCardClicks('.my-hand-section, .dummy-hand-section', (card) => {
                if (self.onMove) {
                    self.onMove({ card_id: card.id });
                }
            });
        },

        renderHandEnd: function() {
            let html = '<div class="bridge-hand-end">';
            html += '<h2>Hand Complete!</h2>';

            const declarer = this.state.declarer;
            const declarerTeam = declarer % 2 === 0 ? 'N/S' : 'E/W';
            const tricksWon = declarerTeam === 'N/S' ? this.state.tricks_ns : this.state.tricks_ew;
            const contractLevel = this.state.contract_level;
            const tricksMade = tricksWon - 6;

            html += '<div class="hand-result">';
            html += `<p><strong>Contract:</strong> ${this.formatContract()}</p>`;
            html += `<p><strong>Tricks won by declarer:</strong> ${tricksWon}</p>`;

            if (tricksMade >= contractLevel) {
                html += `<p class="made">Contract made! ${tricksMade - contractLevel} overtricks</p>`;
            } else {
                html += `<p class="failed">Contract failed by ${contractLevel - tricksMade} tricks</p>`;
            }

            html += `<p><strong>Score:</strong> ${this.state.hand_score}</p>`;
            html += '</div>';

            html += '<div class="total-scores">';
            html += '<h4>Scores</h4>';
            html += `<p>N/S: ${this.state.total_score_ns}</p>`;
            html += `<p>E/W: ${this.state.total_score_ew}</p>`;
            html += '</div>';

            html += '<button id="bridge-next-hand" class="cga-btn cga-btn-primary">Next Hand</button>';
            html += '</div>';

            $('#cga-game-board').html(html);

            $('#bridge-next-hand').on('click', () => {
                if (this.onMove) {
                    this.onMove({ action: 'next_hand' });
                }
            });
        },

        formatBid: function(bid) {
            if (bid.type === 'pass') return 'Pass';
            if (bid.type === 'double') return 'X';
            if (bid.type === 'redouble') return 'XX';
            return `${bid.level}${this.formatSuit(bid.suit)}`;
        },

        formatSuit: function(suit) {
            const symbols = {
                'clubs': '♣',
                'diamonds': '♦',
                'hearts': '♥',
                'spades': '♠',
                'notrump': 'NT'
            };
            return symbols[suit] || suit;
        },

        formatContract: function() {
            if (!this.state.current_contract) return 'None';
            const bid = this.state.current_contract;
            let contract = `${bid.level}${this.formatSuit(bid.suit)}`;
            if (this.state.doubled) contract += ' X';
            if (this.state.redoubled) contract += 'X';
            return contract;
        },

        formatVulnerability: function() {
            const vul = this.state.vulnerability;
            if (vul === 'none') return 'None';
            if (vul === 'ns') return 'N/S Vulnerable';
            if (vul === 'ew') return 'E/W Vulnerable';
            if (vul === 'both') return 'Both Vulnerable';
            return vul;
        }
    };

    window.CGAGames = window.CGAGames || {};
    window.CGAGames.bridge = BridgeRenderer;

})(jQuery);
