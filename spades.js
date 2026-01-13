/**
 * Spades Game Renderer
 */
(function($) {
    'use strict';

    const SpadesRenderer = {
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
            } else if (this.state.phase === 'round_end') {
                this.renderRoundEnd();
            }
        },

        renderBiddingPhase: function() {
            const isMyTurn = this.state.current_turn === this.mySeat;
            const myHand = this.state.hands[this.mySeat];
            const myTeam = this.mySeat % 2;
            const partnerSeat = (this.mySeat + 2) % 4;

            let html = '<div class="spades-bidding">';
            html += '<h3>Bidding Phase</h3>';

            // Show bids
            html += '<div class="bid-status">';
            for (let seat = 0; seat < 4; seat++) {
                const player = this.state.players[seat];
                const bid = this.state.bids[seat];
                const isPartner = seat === partnerSeat;
                const bidText = bid === null ? 'Waiting...' : (bid === 0 ? 'Nil' : bid);
                const teamClass = seat % 2 === myTeam ? 'my-team' : 'opp-team';

                html += `<div class="bid-entry ${teamClass} ${seat === this.mySeat ? 'is-me' : ''}">
                    <span class="player-name">${player.name}${isPartner ? ' (Partner)' : ''}</span>
                    <span class="player-bid">${bidText}</span>
                </div>`;
            }
            html += '</div>';

            // My hand
            html += '<div class="my-hand-preview">';
            html += CGACards.renderHand(myHand, { fanned: true, validCards: [] });
            html += '</div>';

            // Bid buttons if my turn
            if (isMyTurn) {
                html += '<div class="cga-bid-area">';
                html += '<p>Select your bid:</p>';
                html += '<div class="cga-bid-buttons">';
                html += '<button class="cga-bid-btn pass" data-bid="nil">Nil</button>';
                for (let i = 1; i <= 13; i++) {
                    html += `<button class="cga-bid-btn" data-bid="${i}">${i}</button>`;
                }
                html += '</div></div>';
            } else {
                html += `<p class="waiting">Waiting for ${this.state.players[this.state.current_turn].name} to bid...</p>`;
            }

            html += '</div>';

            $('#cga-game-board').html(html);

            if (isMyTurn) {
                this.bindBidEvents();
            }
        },

        bindBidEvents: function() {
            const self = this;
            $('.cga-bid-btn').on('click', function() {
                const bid = $(this).data('bid');
                if (self.onMove) {
                    self.onMove({ bid: bid });
                }
            });
        },

        renderPlayingPhase: function() {
            const currentTurn = this.state.current_turn;
            const isMyTurn = currentTurn === this.mySeat;
            const myHand = this.state.hands[this.mySeat];
            const myTeam = this.mySeat % 2;

            let html = '<div class="spades-table cga-table">';

            // Render player positions
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
                html += '<div class="trick-empty">♠ Spades Trump</div>';
            }

            html += '</div>';

            if (this.state.spades_broken) {
                html += '<div class="spades-broken">♠ Spades Broken</div>';
            }

            html += '</div>';
            html += '</div>';

            // My hand
            html += '<div class="my-hand-section">';
            const validMoves = isMyTurn ? this.getValidCardIds() : [];
            html += CGACards.renderHand(myHand, {
                selectedCards: [],
                validCards: validMoves
            });
            html += '</div>';

            // Team scoreboard
            html += this.renderTeamScoreboard();

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
            const bid = this.state.bids[seat];
            const tricks = this.state.tricks_won[seat];
            const bidText = bid === 0 ? 'Nil' : bid;

            let html = CGACards.renderPlayerInfo(player, {
                isActive: isActive,
                showScore: false,
                extras: `<div class="bid-tricks">Bid: ${bidText} | Won: ${tricks}</div>`
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
            const isLeading = trick.length === 0;

            if (isLeading) {
                if (this.state.spades_broken) {
                    return myHand.map(c => c.id);
                }
                const nonSpades = myHand.filter(c => c.suit !== 'spades');
                return nonSpades.length > 0 ? nonSpades.map(c => c.id) : myHand.map(c => c.id);
            }

            const leadSuit = trick[0].card.suit;
            const suitCards = myHand.filter(c => c.suit === leadSuit);

            if (suitCards.length > 0) {
                return suitCards.map(c => c.id);
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

        renderTeamScoreboard: function() {
            const myTeam = this.mySeat % 2;
            const teams = this.state.teams;
            const scores = this.state.team_scores;
            const bags = this.state.team_bags;

            let html = '<div class="spades-scoreboard cga-scoreboard">';
            html += '<table>';
            html += '<tr><th>Team</th><th>Score</th><th>Bags</th></tr>';

            for (let t = 0; t < 2; t++) {
                const teamPlayers = teams[t].map(s => this.state.players[s].name).join(' & ');
                const isMyTeam = t === myTeam;
                html += `<tr class="${isMyTeam ? 'my-team' : ''}">
                    <td>${teamPlayers}${isMyTeam ? ' (You)' : ''}</td>
                    <td>${scores[t]}</td>
                    <td>${bags[t]}</td>
                </tr>`;
            }

            html += '</table></div>';
            return html;
        },

        renderRoundEnd: function() {
            let html = '<div class="spades-round-end">';
            html += '<h2>Round Complete!</h2>';
            html += this.renderTeamScoreboard();
            html += '<button id="spades-next-round" class="cga-btn cga-btn-primary">Next Round</button>';
            html += '</div>';

            $('#cga-game-board').html(html);

            // Bind next round button
            $('#spades-next-round').on('click', () => {
                if (this.onMove) {
                    this.onMove({ action: 'next_round' });
                }
            });
        }
    };

    window.CGAGames = window.CGAGames || {};
    window.CGAGames.spades = SpadesRenderer;

})(jQuery);
