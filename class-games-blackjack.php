<?php
/**
 * Blackjack (21) Game Module
 *
 * Classic casino card game
 * - 1-7 players vs dealer
 * - Goal: Beat dealer without going over 21
 * - Configurable rules (dealer hits soft 17, double down, split, etc.)
 * - Blackjack pays 3:2 (or 6:5)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CGA_Game_Blackjack extends CGA_Game_Contract {

    protected $id = 'blackjack';
    protected $name = 'Blackjack';
    protected $type = 'card';
    protected $min_players = 1;
    protected $max_players = 7;
    protected $has_teams = false;
    protected $ai_supported = true;

    /**
     * Register the game
     */
    public function register_game(): array {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'type'         => $this->type,
            'min_players'  => $this->min_players,
            'max_players'  => $this->max_players,
            'has_teams'    => $this->has_teams,
            'ai_supported' => $this->ai_supported,
            'description'  => 'Beat the dealer by getting as close to 21 as possible without going over!',
        ];
    }

    /**
     * Initialize game state
     */
    public function init_state( array $players, array $settings = [] ): array {
        // Configurable settings
        $num_decks = $settings['deck_count'] ?? 6;
        $dealer_hits_soft_17 = $settings['dealer_hits_soft_17'] ?? true;
        $double_down_rules = $settings['double_down_rules'] ?? 'any_two_cards';
        $split_rules = $settings['split_rules'] ?? 'once';
        $blackjack_payout = $settings['blackjack_payout'] ?? '3:2'; // or '6:5'

        $player_count = count( $players );

        return [
            'phase'                  => 'betting', // betting, player_actions, dealer_play, payout
            'current_player'         => 0,
            'players'                => $this->format_players( $players, $settings['starting_chips'] ?? 1000 ),
            'player_count'           => $player_count,
            'hands'                  => array_fill( 0, $player_count, [] ),
            'dealer_hand'            => [],
            'bets'                   => array_fill( 0, $player_count, 0 ),
            'player_status'          => array_fill( 0, $player_count, 'waiting' ), // waiting, playing, stand, bust, blackjack
            'deck_count'             => 0,
            'num_decks'              => $num_decks,
            'dealer_hits_soft_17'    => $dealer_hits_soft_17,
            'double_down_rules'      => $double_down_rules,
            'split_rules'            => $split_rules,
            'blackjack_payout'       => $blackjack_payout,
            'results'                => [],
            'payouts'                => [],
            'round_number'           => 1,
            'game_over'              => false,
        ];
    }

    /**
     * Format players with chips
     */
    private function format_players( array $players, int $starting_chips ): array {
        $formatted = [];
        foreach ( $players as $player ) {
            $seat = (int) $player['seat_position'];
            $formatted[ $seat ] = [
                'name'  => $player['display_name'],
                'is_ai' => (bool) $player['is_ai'],
                'chips' => $starting_chips,
            ];
        }
        return $formatted;
    }

    /**
     * Deal cards
     */
    public function deal_or_setup( array $state ): array {
        // Create multiple decks
        $deck = [];
        for ( $i = 0; $i < $state['num_decks']; $i++ ) {
            $deck = array_merge( $deck, $this->create_standard_deck() );
        }

        $deck = $this->shuffle_deck( $deck );
        $this->deck = $deck; // Store for drawing

        // Reset for new round
        $state['hands'] = array_fill( 0, $state['player_count'], [] );
        $state['dealer_hand'] = [];
        $state['bets'] = array_fill( 0, $state['player_count'], 0 );
        $state['player_status'] = array_fill( 0, $state['player_count'], 'waiting' );
        $state['phase'] = 'betting';
        $state['current_player'] = 0;
        $state['deck_count'] = count( $deck );

        return $state;
    }

    /**
     * Validate move
     */
    public function validate_move( array $state, int $player_seat, array $move ) {
        $action = $move['action'] ?? null;

        if ( $state['phase'] === 'betting' ) {
            if ( $action === 'place_bet' ) {
                $amount = $move['amount'] ?? 0;

                if ( $amount <= 0 ) {
                    return new WP_Error( 'invalid_bet', 'Bet must be positive.' );
                }

                if ( $amount > $state['players'][ $player_seat ]['chips'] ) {
                    return new WP_Error( 'insufficient_chips', 'Not enough chips.' );
                }

                if ( $state['bets'][ $player_seat ] > 0 ) {
                    return new WP_Error( 'already_bet', 'You have already placed a bet.' );
                }

                return true;
            }
        }

        if ( $state['phase'] === 'player_actions' ) {
            if ( $state['current_player'] !== $player_seat ) {
                return new WP_Error( 'not_your_turn', 'It is not your turn.' );
            }

            if ( in_array( $action, [ 'hit', 'stand', 'double', 'split' ], true ) ) {
                return true;
            }

            return new WP_Error( 'invalid_action', 'Invalid action.' );
        }

        return new WP_Error( 'invalid_phase', 'Cannot make moves in this phase.' );
    }

    /**
     * Apply move
     */
    public function apply_move( array $state, int $player_seat, array $move ): array {
        $action = $move['action'] ?? null;

        if ( $state['phase'] === 'betting' ) {
            if ( $action === 'place_bet' ) {
                $amount = $move['amount'];
                $state['bets'][ $player_seat ] = $amount;
                $state['players'][ $player_seat ]['chips'] -= $amount;

                // Check if all players have bet
                $all_bet = true;
                foreach ( $state['bets'] as $bet ) {
                    if ( $bet === 0 ) {
                        $all_bet = false;
                        break;
                    }
                }

                if ( $all_bet ) {
                    // Start dealing
                    $state = $this->deal_initial_cards( $state );
                    $state['phase'] = 'player_actions';
                    $state['current_player'] = 0;
                }
            }
        } elseif ( $state['phase'] === 'player_actions' ) {
            if ( $action === 'hit' ) {
                $state = $this->player_hit( $state, $player_seat );
            } elseif ( $action === 'stand' ) {
                $state['player_status'][ $player_seat ] = 'stand';
                $state = $this->advance_to_next_player( $state );
            } elseif ( $action === 'double' ) {
                // Double the bet and take one card
                $additional_bet = $state['bets'][ $player_seat ];
                $state['bets'][ $player_seat ] *= 2;
                $state['players'][ $player_seat ]['chips'] -= $additional_bet;

                $state = $this->player_hit( $state, $player_seat );
                if ( $state['player_status'][ $player_seat ] !== 'bust' ) {
                    $state['player_status'][ $player_seat ] = 'stand';
                }
                $state = $this->advance_to_next_player( $state );
            } elseif ( $action === 'split' ) {
                // Simplified: would split hand into two
                $state['player_status'][ $player_seat ] = 'stand';
                $state = $this->advance_to_next_player( $state );
            }
        }

        return $state;
    }

    /**
     * Deal initial cards (2 to each player, 2 to dealer)
     */
    private function deal_initial_cards( array $state ): array {
        // Give each player 2 cards
        for ( $round = 0; $round < 2; $round++ ) {
            foreach ( $state['players'] as $seat => $player ) {
                $state['hands'][ $seat ][] = $this->draw_card();
            }
            $state['dealer_hand'][] = $this->draw_card();
        }

        // Check for blackjacks
        foreach ( $state['players'] as $seat => $player ) {
            if ( $this->is_blackjack( $state['hands'][ $seat ] ) ) {
                $state['player_status'][ $seat ] = 'blackjack';
            } else {
                $state['player_status'][ $seat ] = 'playing';
            }
        }

        return $state;
    }

    /**
     * Player hits
     */
    private function player_hit( array $state, int $player_seat ): array {
        $card = $this->draw_card();
        $state['hands'][ $player_seat ][] = $card;

        $hand_value = $this->calculate_hand_value( $state['hands'][ $player_seat ] );

        if ( $hand_value > 21 ) {
            $state['player_status'][ $player_seat ] = 'bust';
            $state = $this->advance_to_next_player( $state );
        }

        return $state;
    }

    /**
     * Advance to next player or dealer
     */
    private function advance_to_next_player( array $state ): array {
        // Find next player who is still playing
        $next = ( $state['current_player'] + 1 ) % $state['player_count'];
        $found = false;

        for ( $i = 0; $i < $state['player_count']; $i++ ) {
            $check_seat = ( $state['current_player'] + 1 + $i ) % $state['player_count'];

            if ( $state['player_status'][ $check_seat ] === 'playing' ) {
                $state['current_player'] = $check_seat;
                $found = true;
                break;
            }
        }

        if ( ! $found ) {
            // All players done - dealer's turn
            $state['phase'] = 'dealer_play';
            $state = $this->dealer_play( $state );
        }

        return $state;
    }

    /**
     * Dealer plays
     */
    private function dealer_play( array $state ): array {
        // Dealer must hit until 17 or higher
        while ( true ) {
            $hand_value = $this->calculate_hand_value( $state['dealer_hand'] );

            if ( $hand_value >= 17 ) {
                // Check soft 17 rule
                if ( $hand_value === 17 && $this->is_soft_hand( $state['dealer_hand'] ) && $state['dealer_hits_soft_17'] ) {
                    // Hit on soft 17
                    $state['dealer_hand'][] = $this->draw_card();
                    continue;
                }

                break;
            }

            $state['dealer_hand'][] = $this->draw_card();
        }

        // Calculate results
        $state = $this->calculate_results( $state );
        $state['phase'] = 'payout';

        return $state;
    }

    /**
     * Calculate results and payouts
     */
    private function calculate_results( array $state ): array {
        $dealer_value = $this->calculate_hand_value( $state['dealer_hand'] );
        $dealer_bust = $dealer_value > 21;
        $dealer_blackjack = $this->is_blackjack( $state['dealer_hand'] );

        $state['results'] = [];
        $state['payouts'] = [];

        foreach ( $state['players'] as $seat => $player ) {
            $bet = $state['bets'][ $seat ];
            $player_value = $this->calculate_hand_value( $state['hands'][ $seat ] );
            $player_blackjack = $state['player_status'][ $seat ] === 'blackjack';
            $player_bust = $state['player_status'][ $seat ] === 'bust';

            $result = 'push';
            $payout = 0;

            if ( $player_bust ) {
                // Player busts - loses bet
                $result = 'lose';
                $payout = -$bet;
            } elseif ( $player_blackjack && ! $dealer_blackjack ) {
                // Player blackjack (not dealer)
                $result = 'win';
                $multiplier = $state['blackjack_payout'] === '3:2' ? 1.5 : 1.2;
                $payout = (int) ( $bet * $multiplier );
            } elseif ( $dealer_bust ) {
                // Dealer busts - player wins
                $result = 'win';
                $payout = $bet;
            } elseif ( $player_value > $dealer_value ) {
                // Player has higher value
                $result = 'win';
                $payout = $bet;
            } elseif ( $player_value < $dealer_value ) {
                // Dealer has higher value
                $result = 'lose';
                $payout = -$bet;
            } else {
                // Push (tie)
                $result = 'push';
                $payout = 0;
            }

            $state['results'][ $seat ] = $result;
            $state['payouts'][ $seat ] = $payout;

            // Apply payout
            $state['players'][ $seat ]['chips'] += $bet + $payout;
        }

        return $state;
    }

    /**
     * Calculate hand value
     */
    private function calculate_hand_value( array $hand ): int {
        $value = 0;
        $aces = 0;

        foreach ( $hand as $card ) {
            $rank = $card['rank'];

            if ( $rank === 'A' ) {
                $aces++;
                $value += 11;
            } elseif ( in_array( $rank, [ 'K', 'Q', 'J' ], true ) ) {
                $value += 10;
            } else {
                $value += (int) $rank;
            }
        }

        // Adjust for aces
        while ( $value > 21 && $aces > 0 ) {
            $value -= 10;
            $aces--;
        }

        return $value;
    }

    /**
     * Check if hand is blackjack (A + 10-value card)
     */
    private function is_blackjack( array $hand ): bool {
        if ( count( $hand ) !== 2 ) {
            return false;
        }

        $has_ace = false;
        $has_ten = false;

        foreach ( $hand as $card ) {
            if ( $card['rank'] === 'A' ) {
                $has_ace = true;
            }
            if ( in_array( $card['rank'], [ 'K', 'Q', 'J', '10' ], true ) ) {
                $has_ten = true;
            }
        }

        return $has_ace && $has_ten;
    }

    /**
     * Check if hand is soft (has ace counted as 11)
     */
    private function is_soft_hand( array $hand ): bool {
        $value_hard = 0;
        $aces = 0;

        foreach ( $hand as $card ) {
            $rank = $card['rank'];

            if ( $rank === 'A' ) {
                $aces++;
                $value_hard += 1;
            } elseif ( in_array( $rank, [ 'K', 'Q', 'J' ], true ) ) {
                $value_hard += 10;
            } else {
                $value_hard += (int) $rank;
            }
        }

        // Soft if we can count one ace as 11 and stay <= 21
        if ( $aces > 0 && $value_hard + 10 <= 21 ) {
            return true;
        }

        return false;
    }

    /**
     * Draw a card from deck
     */
    private function draw_card(): array {
        if ( empty( $this->deck ) ) {
            // Reshuffle (shouldn't happen with multiple decks)
            $this->deck = $this->shuffle_deck( $this->create_standard_deck() );
        }

        return array_pop( $this->deck );
    }

    private $deck = []; // Temporary deck storage

    /**
     * Advance turn (not really used in blackjack)
     */
    public function advance_turn( array $state ): array {
        return $state;
    }

    /**
     * Check end condition
     */
    public function check_end_condition( array $state ): array {
        // Game continues until players run out of chips
        // This is simplified - would check if all players are broke

        return [ 'ended' => false, 'reason' => null, 'winners' => null ];
    }

    /**
     * AI move
     */
    public function ai_move( array $state, int $player_seat, string $difficulty = 'beginner' ): array {
        if ( $state['phase'] === 'betting' ) {
            // Bet a fixed amount (simplified)
            $chips = $state['players'][ $player_seat ]['chips'];
            $bet_amount = min( 10, $chips );

            return [
                'action' => 'place_bet',
                'amount' => $bet_amount,
            ];
        }

        if ( $state['phase'] === 'player_actions' ) {
            $hand = $state['hands'][ $player_seat ];
            $hand_value = $this->calculate_hand_value( $hand );
            $dealer_up_card = $state['dealer_hand'][0];
            $dealer_value = $this->calculate_hand_value( [ $dealer_up_card ] );

            // Basic strategy (simplified)
            if ( $hand_value <= 11 ) {
                return [ 'action' => 'hit' ];
            } elseif ( $hand_value >= 17 ) {
                return [ 'action' => 'stand' ];
            } elseif ( $hand_value >= 13 && $dealer_value <= 6 ) {
                return [ 'action' => 'stand' ];
            } else {
                return [ 'action' => 'hit' ];
            }
        }

        return [];
    }

    /**
     * Get valid moves
     */
    public function get_valid_moves( array $state, int $player_seat ): array {
        $valid = [];

        if ( $state['phase'] === 'betting' ) {
            if ( $state['bets'][ $player_seat ] === 0 ) {
                $valid[] = [ 'action' => 'place_bet' ];
            }
        }

        if ( $state['phase'] === 'player_actions' && $state['current_player'] === $player_seat ) {
            if ( $state['player_status'][ $player_seat ] === 'playing' ) {
                $valid[] = [ 'action' => 'hit' ];
                $valid[] = [ 'action' => 'stand' ];

                $hand = $state['hands'][ $player_seat ];
                if ( count( $hand ) === 2 ) {
                    $valid[] = [ 'action' => 'double' ];

                    if ( $hand[0]['rank'] === $hand[1]['rank'] ) {
                        $valid[] = [ 'action' => 'split' ];
                    }
                }
            }
        }

        return $valid;
    }

    /**
     * Get public state
     */
    public function get_public_state( array $state, int $player_seat ): array {
        $public = $state;

        // Hide other players' hands during play
        if ( $state['phase'] === 'player_actions' ) {
            $public['hands'] = [];
            foreach ( $state['hands'] as $seat => $hand ) {
                if ( $seat === $player_seat || $state['player_status'][ $seat ] !== 'playing' ) {
                    $public['hands'][ $seat ] = $hand;
                } else {
                    $public['hands'][ $seat ] = count( $hand );
                }
            }
        }

        return $public;
    }

    // Helper methods
    protected function get_card_value( array $card ): int {
        $rank = $card['rank'];

        if ( $rank === 'A' ) return 11;
        if ( in_array( $rank, [ 'K', 'Q', 'J' ], true ) ) return 10;
        return (int) $rank;
    }
}
