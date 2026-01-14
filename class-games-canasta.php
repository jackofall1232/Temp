<?php
/**
 * Canasta Game Module
 *
 * Rummy-style game with melds and canastas
 * - 2-6 players (usually 4 in teams of 2)
 * - Uses 2 decks + 4 jokers (108 cards)
 * - Meld cards of same rank
 * - 7+ cards = Canasta (bonus)
 * - Configurable house rules
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CGA_Game_Canasta extends CGA_Game_Contract {

    protected $id = 'canasta';
    protected $name = 'Canasta';
    protected $type = 'card';
    protected $min_players = 2;
    protected $max_players = 6;
    protected $has_teams = true;
    protected $ai_supported = true;

    const WINNING_SCORE = 5000;

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
            'description'  => 'Meld cards of the same rank and form Canastas to score points!',
        ];
    }

    /**
     * Initialize game state
     */
    public function init_state( array $players, array $settings = [] ): array {
        $player_count = count( $players );
        $has_teams = $player_count === 4 || $player_count === 6;

        // Configurable settings
        $minimum_meld = $settings['minimum_meld_score'] ?? 50;
        $wildcard_limit = $settings['wildcard_limit_per_meld'] ?? 2;

        return [
            'phase'                 => 'draw', // draw, meld, discard, hand_end
            'current_turn'          => 0,
            'players'               => $this->format_players( $players ),
            'player_count'          => $player_count,
            'has_teams'             => $has_teams,
            'hands'                 => [],
            'melds'                 => [], // Melds per player/team
            'discard_pile'          => [],
            'deck_count'            => 0,
            'red_threes'            => [], // Red 3s are auto-scored
            'minimum_meld_score'    => $minimum_meld, // First meld requirement
            'wildcard_limit'        => $wildcard_limit,
            'hand_scores'           => [],
            'team_scores'           => [ 0, 0 ],
            'round_number'          => 1,
            'going_out_asking'      => false,
            'game_over'             => false,
        ];
    }

    /**
     * Format players
     */
    private function format_players( array $players ): array {
        $formatted = [];
        foreach ( $players as $player ) {
            $seat = (int) $player['seat_position'];
            $formatted[ $seat ] = [
                'name'  => $player['display_name'],
                'is_ai' => (bool) $player['is_ai'],
            ];
        }
        return $formatted;
    }

    /**
     * Deal cards
     */
    public function deal_or_setup( array $state ): array {
        // Create 2 decks with jokers
        $deck = [];
        for ( $i = 0; $i < 2; $i++ ) {
            $deck = array_merge( $deck, $this->create_standard_deck() );
        }

        // Add 4 jokers
        for ( $i = 0; $i < 4; $i++ ) {
            $deck[] = [
                'id'    => 'joker_' . $i,
                'rank'  => 'joker',
                'suit'  => 'wild',
                'value' => 50,
            ];
        }

        $deck = $this->shuffle_deck( $deck );

        // Deal cards (11 each for 2-3 players, 15 for 4+ players)
        $cards_per_player = $state['player_count'] <= 3 ? 11 : 15;
        $deal = $this->deal_cards( $deck, $state['player_count'], $cards_per_player );

        $state['hands'] = $deal['hands'];
        $remaining = $deal['remaining'];

        // Flip first card to start discard pile
        $state['discard_pile'] = [ array_pop( $remaining ) ];
        $state['deck_count'] = count( $remaining );

        // Sort hands
        foreach ( $state['hands'] as $seat => $hand ) {
            $state['hands'][ $seat ] = $this->sort_hand_by_rank( $hand );
        }

        // Initialize melds
        $state['melds'] = array_fill( 0, $state['player_count'], [] );

        // Handle red threes (auto-draw replacement)
        $state = $this->handle_red_threes( $state );

        // Reset hand state
        $state['phase'] = 'draw';
        $state['current_turn'] = 0;
        $state['hand_scores'] = array_fill( 0, $state['player_count'], 0 );

        return $state;
    }

    /**
     * Sort hand by rank
     */
    private function sort_hand_by_rank( array $hand ): array {
        $rank_order = [
            'joker' => 50,
            '2'     => 49, // Wildcards
            'A'     => 14, 'K' => 13, 'Q' => 12, 'J' => 11, '10' => 10,
            '9'     => 9, '8' => 8, '7' => 7, '6' => 6, '5' => 5, '4' => 4, '3' => 3,
        ];

        usort( $hand, function( $a, $b ) use ( $rank_order ) {
            $a_val = $rank_order[ $a['rank'] ] ?? 0;
            $b_val = $rank_order[ $b['rank'] ] ?? 0;
            return $b_val - $a_val; // Descending
        });

        return $hand;
    }

    /**
     * Handle red threes (automatically laid down and replaced)
     */
    private function handle_red_threes( array $state ): array {
        // Simplified - would auto-draw replacements
        return $state;
    }

    /**
     * Validate move
     */
    public function validate_move( array $state, int $player_seat, array $move ) {
        if ( $state['current_turn'] !== $player_seat ) {
            return new WP_Error( 'not_your_turn', 'It is not your turn.' );
        }

        $action = $move['action'] ?? null;

        if ( $state['phase'] === 'draw' ) {
            if ( $action === 'draw_deck' || $action === 'draw_pile' ) {
                return true;
            }
            return new WP_Error( 'invalid_action', 'Must draw a card.' );
        }

        if ( $state['phase'] === 'meld' ) {
            if ( $action === 'create_meld' ) {
                return $this->validate_meld( $state, $player_seat, $move );
            }
            if ( $action === 'add_to_meld' || $action === 'skip_meld' ) {
                return true;
            }
            return new WP_Error( 'invalid_action', 'Invalid meld action.' );
        }

        if ( $state['phase'] === 'discard' ) {
            if ( $action === 'discard' ) {
                $card_id = $move['card_id'] ?? null;
                if ( ! $card_id ) {
                    return new WP_Error( 'no_card', 'Must specify card to discard.' );
                }

                $hand = $state['hands'][ $player_seat ];
                $card = $this->find_card_in_hand( $hand, $card_id );

                if ( ! $card ) {
                    return new WP_Error( 'invalid_card', 'You do not have that card.' );
                }

                return true;
            }
            return new WP_Error( 'invalid_action', 'Must discard a card.' );
        }

        return new WP_Error( 'invalid_phase', 'Cannot make moves in this phase.' );
    }

    /**
     * Validate meld
     */
    private function validate_meld( array $state, int $player_seat, array $move ) {
        $cards = $move['cards'] ?? [];

        if ( count( $cards ) < 3 ) {
            return new WP_Error( 'insufficient_cards', 'Need at least 3 cards to meld.' );
        }

        $hand = $state['hands'][ $player_seat ];

        // Verify all cards are in hand
        foreach ( $cards as $card_id ) {
            if ( ! $this->find_card_in_hand( $hand, $card_id ) ) {
                return new WP_Error( 'invalid_card', 'You do not have that card.' );
            }
        }

        // Verify all cards are same rank (or wildcards)
        $ranks = [];
        $wildcards = 0;

        foreach ( $cards as $card_id ) {
            $card = $this->find_card_in_hand( $hand, $card_id );

            if ( $this->is_wildcard( $card ) ) {
                $wildcards++;
            } else {
                $ranks[] = $card['rank'];
            }
        }

        // All natural cards must be same rank
        $unique_ranks = array_unique( $ranks );
        if ( count( $unique_ranks ) > 1 ) {
            return new WP_Error( 'mixed_ranks', 'All cards must be the same rank (except wildcards).' );
        }

        // Check wildcard limit
        if ( $wildcards > $state['wildcard_limit'] ) {
            return new WP_Error( 'too_many_wildcards', 'Too many wildcards in meld.' );
        }

        // Must have more natural cards than wildcards
        if ( $wildcards >= count( $ranks ) ) {
            return new WP_Error( 'wildcard_limit', 'Must have more natural cards than wildcards.' );
        }

        return true;
    }

    /**
     * Apply move
     */
    public function apply_move( array $state, int $player_seat, array $move ): array {
        $action = $move['action'] ?? null;

        if ( $state['phase'] === 'draw' ) {
            if ( $action === 'draw_deck' ) {
                // Draw from deck (simplified)
                $state['deck_count']--;
                $state['phase'] = 'meld';
            } elseif ( $action === 'draw_pile' ) {
                // Take discard pile (simplified)
                $state['discard_pile'] = [];
                $state['phase'] = 'meld';
            }
        } elseif ( $state['phase'] === 'meld' ) {
            if ( $action === 'create_meld' ) {
                $cards = $move['cards'];
                $hand = $state['hands'][ $player_seat ];

                // Remove cards from hand
                $meld_cards = [];
                foreach ( $cards as $card_id ) {
                    $card = $this->find_card_in_hand( $hand, $card_id );
                    if ( $card ) {
                        $meld_cards[] = $card;
                        $hand = array_values( array_filter( $hand, fn( $c ) => $c['id'] !== $card_id ) );
                    }
                }

                $state['hands'][ $player_seat ] = $hand;
                $state['melds'][ $player_seat ][] = [
                    'cards' => $meld_cards,
                    'rank'  => $meld_cards[0]['rank'],
                ];

                // Stay in meld phase
            } elseif ( $action === 'skip_meld' ) {
                $state['phase'] = 'discard';
            } elseif ( $action === 'add_to_meld' ) {
                // Simplified - would add to specific meld
                $state['phase'] = 'discard';
            }
        } elseif ( $state['phase'] === 'discard' ) {
            if ( $action === 'discard' ) {
                $card_id = $move['card_id'];
                $hand = $state['hands'][ $player_seat ];
                $card = $this->find_card_in_hand( $hand, $card_id );

                // Remove from hand
                $state['hands'][ $player_seat ] = array_values( array_filter( $hand, fn( $c ) => $c['id'] !== $card_id ) );

                // Add to discard pile
                $state['discard_pile'][] = $card;

                // Check if hand is empty (going out)
                if ( empty( $state['hands'][ $player_seat ] ) ) {
                    $state['phase'] = 'hand_end';
                    $state = $this->score_hand( $state );
                } else {
                    // Next player's turn
                    $state['phase'] = 'draw';
                }
            }
        }

        return $state;
    }

    /**
     * Advance turn
     */
    public function advance_turn( array $state ): array {
        if ( $state['phase'] === 'draw' ) {
            $state['current_turn'] = ( $state['current_turn'] + 1 ) % $state['player_count'];
        }

        return $state;
    }

    /**
     * Score hand
     */
    public function score_hand( array $state ): array {
        // Calculate scores for each player
        foreach ( $state['players'] as $seat => $player ) {
            $score = 0;

            // Points from melds
            foreach ( $state['melds'][ $seat ] as $meld ) {
                foreach ( $meld['cards'] as $card ) {
                    $score += $this->get_card_points( $card );
                }

                // Canasta bonuses
                if ( count( $meld['cards'] ) >= 7 ) {
                    $is_natural = $this->is_natural_canasta( $meld );
                    $score += $is_natural ? 500 : 300;
                }
            }

            // Subtract points from cards left in hand
            foreach ( $state['hands'][ $seat ] as $card ) {
                $score -= $this->get_card_points( $card );
            }

            $state['hand_scores'][ $seat ] = $score;

            // Add to team score if teams
            if ( $state['has_teams'] ) {
                $team = $seat % 2;
                $state['team_scores'][ $team ] += $score;
            }
        }

        return $state;
    }

    /**
     * Get point value of card
     */
    private function get_card_points( array $card ): int {
        $rank = $card['rank'];

        if ( $rank === 'joker' ) return 50;
        if ( $rank === '2' ) return 20; // Wildcards
        if ( $rank === 'A' ) return 20;
        if ( in_array( $rank, [ 'K', 'Q', 'J', '10', '9', '8' ], true ) ) return 10;
        if ( in_array( $rank, [ '7', '6', '5', '4' ], true ) ) return 5;
        if ( $rank === '3' ) return 5;

        return 5; // Default
    }

    /**
     * Check if meld is natural canasta (no wildcards)
     */
    private function is_natural_canasta( array $meld ): bool {
        foreach ( $meld['cards'] as $card ) {
            if ( $this->is_wildcard( $card ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if card is wildcard
     */
    private function is_wildcard( array $card ): bool {
        return $card['rank'] === 'joker' || $card['rank'] === '2';
    }

    /**
     * Check end condition
     */
    public function check_end_condition( array $state ): array {
        if ( $state['phase'] !== 'hand_end' ) {
            return [ 'ended' => false, 'reason' => null, 'winners' => null ];
        }

        // Check if any team/player reached winning score
        if ( $state['has_teams'] ) {
            foreach ( $state['team_scores'] as $team => $score ) {
                if ( $score >= self::WINNING_SCORE ) {
                    return [
                        'ended'   => true,
                        'reason'  => 'score_reached',
                        'winners' => [ $team ],
                    ];
                }
            }
        } else {
            foreach ( $state['hand_scores'] as $seat => $score ) {
                if ( $score >= self::WINNING_SCORE ) {
                    return [
                        'ended'   => true,
                        'reason'  => 'score_reached',
                        'winners' => [ $seat ],
                    ];
                }
            }
        }

        return [ 'ended' => false, 'reason' => null, 'winners' => null ];
    }

    /**
     * AI move
     */
    public function ai_move( array $state, int $player_seat, string $difficulty = 'beginner' ): array {
        $phase = $state['phase'];

        if ( $phase === 'draw' ) {
            // Simplified: always draw from deck
            return [ 'action' => 'draw_deck' ];
        }

        if ( $phase === 'meld' ) {
            // Try to find a valid meld
            $hand = $state['hands'][ $player_seat ];
            $meld = $this->ai_find_meld( $hand );

            if ( $meld ) {
                return [
                    'action' => 'create_meld',
                    'cards'  => $meld,
                ];
            }

            return [ 'action' => 'skip_meld' ];
        }

        if ( $phase === 'discard' ) {
            // Discard lowest value card
            $hand = $state['hands'][ $player_seat ];
            if ( ! empty( $hand ) ) {
                usort( $hand, fn( $a, $b ) => $this->get_card_points( $a ) - $this->get_card_points( $b ) );
                return [
                    'action'  => 'discard',
                    'card_id' => $hand[0]['id'],
                ];
            }
        }

        return [];
    }

    /**
     * AI find meld in hand
     */
    private function ai_find_meld( array $hand ): ?array {
        // Group by rank
        $by_rank = [];
        foreach ( $hand as $card ) {
            if ( ! $this->is_wildcard( $card ) ) {
                $by_rank[ $card['rank'] ][] = $card;
            }
        }

        // Find rank with 3+ cards
        foreach ( $by_rank as $rank => $cards ) {
            if ( count( $cards ) >= 3 ) {
                return array_map( fn( $c ) => $c['id'], array_slice( $cards, 0, 3 ) );
            }
        }

        return null;
    }

    /**
     * Get valid moves
     */
    public function get_valid_moves( array $state, int $player_seat ): array {
        if ( $state['current_turn'] !== $player_seat ) {
            return [];
        }

        $valid = [];

        if ( $state['phase'] === 'draw' ) {
            $valid[] = [ 'action' => 'draw_deck' ];
            if ( ! empty( $state['discard_pile'] ) ) {
                $valid[] = [ 'action' => 'draw_pile' ];
            }
        }

        // Simplified - would generate all possible melds/discards

        return $valid;
    }

    /**
     * Get public state
     */
    public function get_public_state( array $state, int $player_seat ): array {
        $public = $state;

        // Hide other players' hands
        $public['hands'] = [];
        foreach ( $state['hands'] as $seat => $hand ) {
            if ( $seat === $player_seat ) {
                $public['hands'][ $seat ] = $hand;
            } else {
                $public['hands'][ $seat ] = count( $hand );
            }
        }

        return $public;
    }

    // Helper methods
    private function find_card_in_hand( array $hand, string $card_id ): ?array {
        foreach ( $hand as $card ) {
            if ( $card['id'] === $card_id ) {
                return $card;
            }
        }
        return null;
    }

    protected function get_card_value( array $card ): int {
        return $this->get_card_points( $card );
    }
}
