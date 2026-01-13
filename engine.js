/**
 * Classic Games Arcade - Frontend Engine
 */
(function($) {
    'use strict';

    // Main game controller
    const CGA = {
        config: window.cgaConfig || {},
        state: null,
        room: null,
        pollTimer: null,
        gameRenderer: null,
        mySeat: null,
        
        // Debug helper - shows messages on screen without dev tools
        debug: function(message, data = null) {
            console.log(message, data);
            
            const panel = $('#cga-debug-panel');
            const content = $('#cga-debug-content');
            
            panel.show();
            
            const timestamp = new Date().toLocaleTimeString();
            let output = `[${timestamp}] ${message}`;
            
            if (data) {
                output += '\n' + JSON.stringify(data, null, 2);
            }
            
            content.append($('<div>').text(output).css({
                'border-bottom': '1px solid #333',
                'padding': '3px 0',
                'margin': '2px 0'
            }));
            
            // Auto-scroll to bottom
            content[0].scrollTop = content[0].scrollHeight;
        },

        init: function() {
            console.log('==============================================');
            console.log('CGA ENGINE INITIALIZATION');
            console.log('==============================================');
            console.log('1. Checking for container...');
            
            const container = $('#cga-game-container');
            console.log('   Container found:', container.length > 0);
            
            if (!container.length) {
                console.error('   [X] INIT FAILED: No container found!');
                console.log('   Looking for: #cga-game-container');
                return;
            }
            
            console.log('   [OK] Container exists');
            
            console.log('2. Reading data attributes...');
            this.gameId = container.data('game-id');
            this.roomCode = container.data('room-code');
            console.log('   Game ID:', this.gameId);
            console.log('   Room Code:', this.roomCode || '(none)');
            
            if (!this.gameId) {
                console.error('   [X] No game ID found on container!');
            }
            
            console.log('3. Checking cgaConfig...');
            console.log('   window.cgaConfig exists:', typeof window.cgaConfig !== 'undefined');
            console.log('   Config object:', window.cgaConfig);
            
            if (typeof window.cgaConfig === 'undefined') {
                console.error('   [X] CRITICAL: cgaConfig is undefined!');
                console.error('   The shortcode did not localize the script properly.');
                alert('CRITICAL ERROR: cgaConfig is not defined. Please check console.');
                return;
            }
            
            if (!window.cgaConfig.restUrl) {
                console.error('   [X] CRITICAL: cgaConfig.restUrl is missing!');
                return;
            }
            
            if (!window.cgaConfig.nonce) {
                console.error('   [X] CRITICAL: cgaConfig.nonce is missing!');
                return;
            }
            
            console.log('   [OK] Config valid');
            console.log('   REST URL:', this.config.restUrl);
            console.log('   Nonce:', this.config.nonce ? this.config.nonce.substring(0, 10) + '...' : 'MISSING');
            console.log('   User ID:', this.config.userId);
            console.log('   Poll Interval:', this.config.pollInterval);
            
            console.log('4. Binding events...');
            this.bindEvents();
            console.log('   [OK] Events bound');
            
            console.log('5. Checking for room code in URL...');
            // If room code in URL, try to join
            if (this.roomCode) {
                console.log('   Found room code, attempting to join:', this.roomCode);
                this.joinRoom(this.roomCode);
            } else {
                console.log('   No room code in URL');
            }
            
            console.log('==============================================');
            console.log('CGA ENGINE READY');
            console.log('==============================================');
        },

        bindEvents: function() {
            $('#cga-create-room').on('click', () => this.createRoom());
            $('#cga-join-room').on('click', () => this.joinRoomFromInput());
            $('#cga-room-code-input').on('keypress', (e) => {
                if (e.key === 'Enter') this.joinRoomFromInput();
            });
            $('#cga-add-ai').on('click', () => this.addAI());
            $('#cga-start-game').on('click', () => this.startGame());
            $('#cga-leave-room').on('click', () => this.leaveRoom());
            $('#cga-copy-code').on('click', () => this.copyRoomCode());
            $('#cga-play-again').on('click', () => this.createRoom());
            $('#cga-back-to-lobby').on('click', () => this.showView('lobby'));
        },

        // View management
        showView: function(view) {
            $('.cga-view').removeClass('cga-view-active');
            $(`#cga-${view}`).addClass('cga-view-active');
        },

        showLoading: function(show = true) {
            this.debug('showLoading called: ' + show);
            console.log('showLoading:', show);
            if (show) {
                $('#cga-loading').css('display', 'flex');
            } else {
                $('#cga-loading').css('display', 'none');
            }
        },

        showError: function(message) {
            // Display error prominently on page
            const errorDiv = $('<div class="cga-error-banner">')
                .css({
                    'background': '#f44336',
                    'color': 'white',
                    'padding': '15px',
                    'margin': '10px 0',
                    'border-radius': '4px',
                    'font-weight': 'bold',
                    'text-align': 'center'
                })
                .html('ERROR: ' + message)
                .prependTo('#cga-game-container');
            
            // Also log to console for debugging
            console.error('CGA Error:', message);
            
            // Remove after 5 seconds
            setTimeout(() => errorDiv.fadeOut(() => errorDiv.remove()), 5000);
        },

        // API calls
        api: function(endpoint, method = 'GET', data = null) {
            const options = {
                url: this.config.restUrl + endpoint,
                method: method,
                dataType: 'json',  // Expect JSON response
                headers: {
                    'X-WP-Nonce': this.config.nonce
                }
            };

            if (data) {
                options.contentType = 'application/json';
                options.data = JSON.stringify(data);
            }

            return $.ajax(options);
        },

        // Room management
        createRoom: function() {
            console.log('=== CREATE ROOM START ===');
            console.log('Game ID:', this.gameId);
            console.log('Config:', this.config);
            console.log('API URL:', this.config.restUrl + 'room');
            
            this.showLoading();

            this.api('room', 'POST', { game_id: this.gameId })
                .done((response) => {
                    console.log('=== SUCCESS RESPONSE ===');
                    console.log('Full response:', response);
                    console.log('response.success:', response.success);
                    console.log('response.room:', response.room);
                    
                    if (response.success) {
                        console.log('SUCCESS! Room code:', response.room.room_code);
                        this.roomCode = response.room.room_code;
                        
                        // Load full room data (includes players)
                        this.loadRoom();
                    } else {
                        console.error('Response success was false!');
                        this.showError('Room created but response was unsuccessful. Check console for details.');
                    }
                })
                .fail((xhr) => {
                    console.error('=== FAILURE RESPONSE ===');
                    console.error('XHR object:', xhr);
                    console.error('Status:', xhr.status);
                    console.error('Status text:', xhr.statusText);
                    console.error('Response JSON:', xhr.responseJSON);
                    console.error('Response text:', xhr.responseText);
                    
                    let errorMsg = 'Failed to create room';
                    if (xhr.responseJSON) {
                        errorMsg += ': ' + (xhr.responseJSON.message || xhr.responseJSON.code || JSON.stringify(xhr.responseJSON));
                    } else if (xhr.responseText) {
                        errorMsg += ': ' + xhr.responseText.substring(0, 100);
                    } else if (xhr.status) {
                        errorMsg += ' (HTTP ' + xhr.status + ')';
                    }
                    
                    this.showError(errorMsg);
                })
                .always(() => {
                    console.log('=== CREATE ROOM END ===');
                    this.showLoading(false);
                });
        },

        joinRoomFromInput: function() {
            const code = $('#cga-room-code-input').val().toUpperCase().trim();
            if (code.length !== 6) {
                this.showError('Room code must be 6 characters');
                return;
            }
            this.joinRoom(code);
        },

        joinRoom: function(code) {
            this.showLoading();

            this.api(`room/${code}/join`, 'POST')
                .done((response) => {
                    if (response.success) {
                        this.roomCode = code;
                        this.mySeat = response.player.seat_position;
                        this.loadRoom();
                    }
                })
                .fail((xhr) => {
                    this.showError(xhr.responseJSON?.message || 'Failed to join room');
                    this.showView('lobby');
                })
                .always(() => this.showLoading(false));
        },

        loadRoom: function() {
            this.api(`room/${this.roomCode}`)
                .done((response) => {
                    this.room = response;
                    
                    // Find my seat if not set
                    if (this.mySeat === null) {
                        this.findMySeat();
                    }

                    if (this.room.status === 'active') {
                        this.loadGameState();
                    } else if (this.room.status === 'completed') {
                        this.showGameOver();
                    } else {
                        this.showRoomView();
                        this.startRoomPolling();
                        
                        // Update URL with room code
                        const url = new URL(window.location);
                        url.searchParams.set('room', this.roomCode);
                        window.history.pushState({}, '', url);
                    }
                })
                .fail(() => {
                    this.showError('Room not found');
                    this.showView('lobby');
                });
        },

        findMySeat: function() {
            if (!this.room?.players) return;

            const userId = this.config.userId;
            const guestToken = this.config.guestToken;

            for (const player of this.room.players) {
                if (userId && player.user_id == userId) {
                    this.mySeat = parseInt(player.seat_position);
                    return;
                }
                if (guestToken && player.guest_token === guestToken) {
                    this.mySeat = parseInt(player.seat_position);
                    return;
                }
            }
        },

        showRoomView: function() {
            $('#cga-room-code-display').text(this.roomCode);
            this.updatePlayersList();
            this.updateStartButton();
            this.showView('room');
        },

        updatePlayersList: function() {
            const list = $('#cga-players');
            list.empty();

            if (!this.room?.players) return;

            const gameMeta = this.room.game_meta || {};
            const maxPlayers = gameMeta.max_players || 4;

            for (let i = 0; i < maxPlayers; i++) {
                const player = this.room.players.find(p => parseInt(p.seat_position) === i);
                
                if (player) {
                    const isMe = parseInt(player.seat_position) === this.mySeat;
                    const aiTag = player.is_ai ? ' <span class="cga-ai-tag">AI</span>' : '';
                    const youTag = isMe ? ' <span class="cga-you-tag">(You)</span>' : '';
                    
                    list.append(`<li class="cga-player-slot cga-player-filled">
                        <span class="cga-seat">Seat ${i + 1}:</span>
                        ${player.display_name}${aiTag}${youTag}
                    </li>`);
                } else {
                    list.append(`<li class="cga-player-slot cga-player-empty">
                        <span class="cga-seat">Seat ${i + 1}:</span>
                        <em>Empty</em>
                    </li>`);
                }
            }
        },

        updateStartButton: function() {
            if (!this.room?.game_meta) return;

            const minPlayers = this.room.game_meta.min_players || 2;
            const currentPlayers = this.room.players?.length || 0;
            const canStart = currentPlayers >= minPlayers;

            $('#cga-start-game')
                .prop('disabled', !canStart)
                .text(canStart ? 'Start Game' : `Need ${minPlayers - currentPlayers} more player(s)`);
        },

        addAI: function() {
            this.api(`room/${this.roomCode}/ai`, 'POST', { difficulty: 'beginner' })
                .done(() => this.loadRoom())
                .fail((xhr) => {
                    this.showError(xhr.responseJSON?.message || 'Failed to add AI');
                });
        },

        leaveRoom: function() {
            this.stopPolling();
            
            this.api(`room/${this.roomCode}/leave`, 'POST')
                .always(() => {
                    this.room = null;
                    this.roomCode = null;
                    this.mySeat = null;
                    
                    // Clear URL
                    const url = new URL(window.location);
                    url.searchParams.delete('room');
                    window.history.pushState({}, '', url);
                    
                    this.showView('lobby');
                });
        },

        copyRoomCode: function() {
            navigator.clipboard.writeText(this.roomCode).then(() => {
                $('#cga-copy-code').text('[OK]');
                setTimeout(() => $('#cga-copy-code').text('Copy'), 1500);
            });
        },

        startGame: function() {
            this.debug('=== START GAME CLICKED ===');
            this.debug('Room code: ' + this.roomCode);
            
            console.log('=== START GAME ===');
            console.log('Room code:', this.roomCode);
            
            this.showLoading();
            this.stopPolling();

            this.api(`room/${this.roomCode}/start`, 'POST')
                .done((response) => {
                    this.debug('START GAME RESPONSE RECEIVED', response);
                    
                    console.log('Start game response:', response);
                    
                    if (response.success) {
                        this.debug('SUCCESS! Game starting...');
                        console.log('Game started successfully');
                        this.state = response.state;
                        
                        try {
                            this.showGameView();
                            this.startGamePolling();
                        } catch (error) {
                            this.debug('ERROR in showGameView: ' + error.message);
                            console.error('Error showing game view:', error);
                            this.showError('Error displaying game: ' + error.message);
                        }
                    } else {
                        this.debug('ERROR: Response success was false', response);
                        console.error('Start game returned success=false');
                        this.showError('Game start was unsuccessful');
                    }
                })
                .fail((xhr) => {
                    this.debug('START GAME FAILED', {
                        status: xhr.status,
                        statusText: xhr.statusText,
                        response: xhr.responseJSON || xhr.responseText
                    });
                    
                    console.error('Start game failed:', xhr);
                    console.error('Status:', xhr.status);
                    console.error('Response:', xhr.responseJSON);
                    
                    let errorMsg = xhr.responseJSON?.message || 'Failed to start game';
                    if (xhr.status) {
                        errorMsg += ' (HTTP ' + xhr.status + ')';
                    }
                    
                    this.showError(errorMsg);
                    this.startRoomPolling();
                })
                .always(() => {
                    this.debug('START GAME COMPLETE - Hiding loading spinner');
                    console.log('Start game complete, hiding loading');
                    this.showLoading(false);
                });
        },

        // Game state
        loadGameState: function() {
            this.api(`game/state/${this.roomCode}`)
                .done((response) => {
                    if (response.state) {
                        this.state = response.state;
                        this.showGameView();
                        this.startGamePolling();
                    } else if (!response.started) {
                        this.room = response.room;
                        this.showRoomView();
                        this.startRoomPolling();
                    }
                })
                .fail(() => {
                    this.showError('Failed to load game state');
                });
        },

        showGameView: function() {
            this.debug('showGameView called');
            this.debug('Current state exists: ' + (this.state ? 'YES' : 'NO'));
            this.showView('game');
            this.renderGame();
        },

        renderGame: function() {
            this.debug('renderGame called');
            
            if (!this.state) {
                this.debug('ERROR: No state available for rendering!');
                return;
            }
            
            this.debug('State data', this.state);

            // Update turn indicator
            const currentTurn = this.state.state.current_turn;
            const isMyTurn = currentTurn === this.mySeat;
            const playerName = this.room?.players?.find(p => parseInt(p.seat_position) === currentTurn)?.display_name || `Player ${currentTurn + 1}`;
            
            $('#cga-current-turn').html(
                isMyTurn 
                    ? '<strong>Your turn!</strong>' 
                    : `Waiting for ${playerName}...`
            );

            // Render game-specific board
            if (window.CGAGames && window.CGAGames[this.gameId]) {
                window.CGAGames[this.gameId].render(this.state, this.mySeat, (move) => this.makeMove(move));
            } else {
                // Fallback: generic state display
                $('#cga-game-board').html('<pre>' + JSON.stringify(this.state.state, null, 2) + '</pre>');
            }

            // Check for game over
            if (this.state.state.game_over) {
                this.showGameOver();
            }
        },

        makeMove: function(move) {
            const isMyTurn = this.state.state.current_turn === this.mySeat;
            if (!isMyTurn) {
                this.showError('Not your turn!');
                return;
            }

            this.api(`game/move/${this.roomCode}`, 'POST', {
                move: move,
                etag: this.state.etag
            })
                .done((response) => {
                    if (response.success) {
                        this.state = response.state;
                        this.renderGame();
                    }
                })
                .fail((xhr) => {
                    const error = xhr.responseJSON;
                    if (error?.code === 'stale_state') {
                        // State changed, refresh
                        this.pollGameState();
                    } else {
                        this.showError(error?.message || 'Invalid move');
                    }
                });
        },

        showGameOver: function() {
            this.stopPolling();
            
            const state = this.state?.state || {};
            const winners = state.winners || [];
            
            let title = 'Game Over';
            if (winners.includes(this.mySeat)) {
                title = '[WIN] You Won!';
            } else if (winners.length > 0) {
                title = 'You Lost';
            }

            $('#cga-gameover-title').text(title);
            
            // Show final scores if available
            let scoresHtml = '';
            if (state.captured) {
                scoresHtml = '<p>Pieces captured:</p><ul>';
                for (const [seat, count] of Object.entries(state.captured)) {
                    const player = this.room?.players?.find(p => parseInt(p.seat_position) === parseInt(seat));
                    scoresHtml += `<li>${player?.display_name || 'Player ' + (parseInt(seat) + 1)}: ${count}</li>`;
                }
                scoresHtml += '</ul>';
            }
            $('#cga-final-scores').html(scoresHtml);

            this.showView('gameover');
        },

        // Polling
        startRoomPolling: function() {
            this.stopPolling();
            this.pollTimer = setInterval(() => this.pollRoom(), this.config.pollInterval || 1500);
        },

        startGamePolling: function() {
            this.stopPolling();
            this.pollTimer = setInterval(() => this.pollGameState(), this.config.pollInterval || 1500);
        },

        stopPolling: function() {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
        },

        pollRoom: function() {
            this.api(`room/${this.roomCode}`)
                .done((response) => {
                    this.room = response;
                    
                    if (response.status === 'active') {
                        this.stopPolling();
                        this.loadGameState();
                    } else {
                        this.updatePlayersList();
                        this.updateStartButton();
                    }
                });
        },

        pollGameState: function() {
            const etag = this.state?.etag || '';
            
            this.api(`game/state/${this.roomCode}?etag=${etag}`)
                .done((response) => {
                    if (response.changed && response.state) {
                        this.state = response.state;
                        if (response.room) {
                            this.room = { ...this.room, ...response.room };
                        }
                        this.renderGame();
                    }
                });
        }
    };

    // Game-specific renderers namespace
    window.CGAGames = window.CGAGames || {};

    // Initialize on ready
    $(document).ready(() => CGA.init());

    // Expose for game renderers
    window.CGA = CGA;

})(jQuery);
