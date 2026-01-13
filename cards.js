/**
 * Shared Card Game Utilities
 */
(function($) {
    'use strict';

    const SUIT_SYMBOLS = {
        hearts: '&hearts;',
        diamonds: '&diams;',
        clubs: '&clubs;',
        spades: '&spades;'
    };

    const RANK_DISPLAY = {
        '2': '2', '3': '3', '4': '4', '5': '5', '6': '6', '7': '7',
        '8': '8', '9': '9', '10': '10', 'J': 'J', 'Q': 'Q', 'K': 'K', 'A': 'A'
    };

    window.CGACards = {
        /**
         * Render a single card
         */
        renderCard: function(card, options = {}) {
            const defaults = {
                faceDown: false,
                selected: false,
                disabled: false,
                clickable: true,
                size: 'normal' // 'small', 'normal', 'large'
            };
            const opts = { ...defaults, ...options };

            if (opts.faceDown) {
                return `<div class="cga-card cga-card-back" data-card-id="${card.id || ''}"></div>`;
            }

            const suitClass = card.suit;
            const selectedClass = opts.selected ? 'selected' : '';
            const disabledClass = opts.disabled ? 'disabled' : '';
            const sizeClass = opts.size !== 'normal' ? `cga-card-${opts.size}` : '';

            return `
                <div class="cga-card ${suitClass} ${selectedClass} ${disabledClass} ${sizeClass}" 
                     data-card-id="${card.id}" 
                     data-suit="${card.suit}" 
                     data-rank="${card.rank}">
                    <span class="cga-card-corner cga-card-corner-tl">
                        ${RANK_DISPLAY[card.rank]}${SUIT_SYMBOLS[card.suit]}
                    </span>
                    <span class="cga-card-suit">${SUIT_SYMBOLS[card.suit]}</span>
                    <span class="cga-card-rank">${RANK_DISPLAY[card.rank]}</span>
                    <span class="cga-card-corner cga-card-corner-br">
                        ${RANK_DISPLAY[card.rank]}${SUIT_SYMBOLS[card.suit]}
                    </span>
                </div>
            `;
        },

        /**
         * Render a hand of cards
         */
        renderHand: function(cards, options = {}) {
            const defaults = {
                fanned: true,
                selectedCards: [],
                validCards: [],
                onCardClick: null
            };
            const opts = { ...defaults, ...options };

            const fannedClass = opts.fanned ? 'cga-hand-fanned' : '';
            let html = `<div class="cga-hand ${fannedClass}">`;

            cards.forEach(card => {
                const isSelected = opts.selectedCards.includes(card.id);
                const isValid = opts.validCards.length === 0 || opts.validCards.includes(card.id);

                html += this.renderCard(card, {
                    selected: isSelected,
                    disabled: !isValid
                });
            });

            html += '</div>';
            return html;
        },

        /**
         * Render opponent's hidden hand
         */
        renderOpponentHand: function(cardCount, options = {}) {
            const defaults = {
                vertical: false,
                maxShow: 13
            };
            const opts = { ...defaults, ...options };

            const verticalClass = opts.vertical ? 'vertical' : '';
            const count = Math.min(cardCount, opts.maxShow);

            let html = `<div class="cga-opponent-hand ${verticalClass}">`;
            for (let i = 0; i < count; i++) {
                html += '<div class="cga-card cga-card-back"></div>';
            }
            html += '</div>';

            return html;
        },

        /**
         * Render a trick (cards played to center)
         */
        renderTrick: function(trickCards, players, options = {}) {
            const defaults = {
                showLabels: true
            };
            const opts = { ...defaults, ...options };

            let html = '<div class="cga-trick">';

            trickCards.forEach(play => {
                const playerName = players[play.seat]?.name || `Player ${play.seat + 1}`;
                html += `
                    <div class="cga-trick-card">
                        ${this.renderCard(play.card)}
                        ${opts.showLabels ? `<span class="cga-player-label">${playerName}</span>` : ''}
                    </div>
                `;
            });

            html += '</div>';
            return html;
        },

        /**
         * Render trump indicator
         */
        renderTrumpIndicator: function(suit) {
            if (!suit) return '';
            return `
                <div class="cga-trump-indicator ${suit}">
                    Trump: ${SUIT_SYMBOLS[suit]}
                </div>
            `;
        },

        /**
         * Render player info box
         */
        renderPlayerInfo: function(player, options = {}) {
            const defaults = {
                isActive: false,
                showScore: true,
                score: 0,
                extras: ''
            };
            const opts = { ...defaults, ...options };

            const activeClass = opts.isActive ? 'active' : '';

            return `
                <div class="cga-player-info ${activeClass}">
                    <div class="name">${player.name}${player.is_ai ? ' <span class="ai-badge">AI</span>' : ''}</div>
                    ${opts.showScore ? `<div class="score">Score: ${opts.score}</div>` : ''}
                    ${opts.extras}
                </div>
            `;
        },

        /**
         * Render bid buttons
         */
        renderBidButtons: function(validBids, onBid) {
            let html = '<div class="cga-bid-buttons">';

            validBids.forEach(bid => {
                const passClass = bid.value === 'pass' ? 'pass' : '';
                html += `
                    <button class="cga-bid-btn ${passClass}" data-bid="${bid.value}">
                        ${bid.label}
                    </button>
                `;
            });

            html += '</div>';
            return html;
        },

        /**
         * Render scoreboard
         */
        renderScoreboard: function(scores, players, options = {}) {
            const defaults = {
                teamGame: false,
                teams: null
            };
            const opts = { ...defaults, ...options };

            let html = '<div class="cga-scoreboard"><table>';

            if (opts.teamGame && opts.teams) {
                html += '<tr><th>Team</th><th>Score</th></tr>';
                opts.teams.forEach((team, idx) => {
                    const teamPlayers = team.map(seat => players[seat]?.name || `P${seat + 1}`).join(' & ');
                    html += `<tr class="cga-team-${idx}"><td>${teamPlayers}</td><td>${scores[idx] || 0}</td></tr>`;
                });
            } else {
                html += '<tr><th>Player</th><th>Score</th></tr>';
                Object.entries(players).forEach(([seat, player]) => {
                    html += `<tr><td>${player.name}</td><td>${scores[seat] || 0}</td></tr>`;
                });
            }

            html += '</table></div>';
            return html;
        },

        /**
         * Sort cards by suit then rank
         */
        sortCards: function(cards, options = {}) {
            const defaults = {
                suitOrder: ['spades', 'hearts', 'diamonds', 'clubs'],
                rankOrder: ['A', 'K', 'Q', 'J', '10', '9', '8', '7', '6', '5', '4', '3', '2'],
                trumpFirst: null
            };
            const opts = { ...defaults, ...options };

            let suitOrder = [...opts.suitOrder];
            if (opts.trumpFirst) {
                suitOrder = [opts.trumpFirst, ...suitOrder.filter(s => s !== opts.trumpFirst)];
            }

            return [...cards].sort((a, b) => {
                const suitDiff = suitOrder.indexOf(a.suit) - suitOrder.indexOf(b.suit);
                if (suitDiff !== 0) return suitDiff;
                return opts.rankOrder.indexOf(a.rank) - opts.rankOrder.indexOf(b.rank);
            });
        },

        /**
         * Get cards that can be legally played
         */
        getPlayableCards: function(hand, leadSuit, trump, mustFollowSuit = true) {
            if (!leadSuit || !mustFollowSuit) {
                return hand.map(c => c.id);
            }

            // Must follow suit if possible
            const suitCards = hand.filter(c => c.suit === leadSuit);
            if (suitCards.length > 0) {
                return suitCards.map(c => c.id);
            }

            // Can play anything
            return hand.map(c => c.id);
        },

        /**
         * Bind card click events
         */
        bindCardClicks: function(container, callback) {
            $(container).on('click', '.cga-card:not(.disabled):not(.cga-card-back)', function() {
                const cardId = $(this).data('card-id');
                const suit = $(this).data('suit');
                const rank = $(this).data('rank');
                callback({ id: cardId, suit, rank }, $(this));
            });
        },

        /**
         * Highlight winning card in trick
         */
        highlightWinner: function(container, winningCardId) {
            $(container).find('.cga-card').removeClass('winner');
            $(container).find(`.cga-card[data-card-id="${winningCardId}"]`).addClass('winner');
        }
    };

})(jQuery);
