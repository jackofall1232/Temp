<?php
/**
 * Hearts Game Module
 * 
 * Classic 4-player Hearts
 * - Avoid hearts and Queen of Spades
 * - Pass 3 cards each round (except every 4th)
 * - Shoot the moon option
 * - First to 100 loses
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CGA_Game_Hearts extends CGA_Game_Contract {

    protected $id = 'hearts';
    protected $name = 'Hearts';
    protected $type = 'card';
    protected $min_players = 4;
    protected $max_players = 4;
    protected $has_teams = false;
    protected $ai_supported = true;

    const POINTS_TO_LOSE = 100;

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
            'description'  => 'Avoid taking hearts and the Queen of Spades. First to 100 points loses!',
        ];
    }

    /**
     * Initialize game state
     */
    public function init_state( array $players, array $settings = [] ): array {
        return [
            'phase'           => 'passing', // passing, playing, round_end
            'current_turn'    => 0,
            'players'         => $this->format_players( $players ),
            'hands'           => [],
            'pass_direction'  => 'left', // left, right, across, none
            'pass_selections' => [ [], [], [], [] ],
            'passes_complete' => [ false, false, false, false ],
            'trick'           => [],
            'trick_leader'    => 0,
            'tricks_taken'    => [ 0, 0, 0, 0 ],
            'hearts_in_trick' => [ 0, 0, 0, 0 ],
            'hearts_broken'   => false,
            'round_scores'    => [ 0, 0, 0, 0 ],
            'total_scores'    => [ 0, 0, 0, 0 ],
            'round_number'    => 1,
            'game_over'       => false,
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
        $deck = $this->create_standard_deck();
        $deck = $this->shuffle_deck( $deck );

        $deal = $this->deal_cards( $deck, 4, 13 );
        $state['hands'] = $deal['hands'];

        // Sort hands
        foreach ( $state['hands'] as $seat => $hand ) {
            $state['hands'][ $seat ] = $this->sort_hand( $hand );
        }

        // Determine pass direction based on round
        $directions = [ 'left', 'right', 'across', 'none' ];
        $state['pass_direction'] = $directions[ ( $state['round_number'] - 1 ) % 4 ];

        // If no passing this round, go straight to playing
        if ( $state['pass_direction'] === 'none' ) {
            $state['phase'] = 'playing';
            $state['current_turn'] = $this->find_two_of_clubs( $state['hands'] );
            $state['trick_leader'] = $state['current_turn'];
        } else {
            $state['phase'] = 'passing';
            $state['pass_selections'] = [ [], [], [], [] ];
            $state['passes_complete'] = [ false, false, false, false ];
        }

        $state['trick'] = [];
        $state['tricks_taken'] = [ 0, 0, 0, 0 ];
        $state['hearts_in_trick'] = [ 0, 0, 0, 0 ];
        $state['hearts_broken'] = false;
        $state['round_scores'] = [ 0, 0, 0, 0 ];

        return $state;
    }

    /**
     * Sort hand by suit and rank
     */
    private function sort_hand( array $hand ): array {
        $suit_order = [ 'spades' => 0, 'hearts' => 1, 'diamonds' => 2, 'clubs' => 3 ];
        $rank_order = [ 'A' => 14, 'K' => 13, 'Q' => 12, 'J' => 11, '10' => 10, '9' => 9, '8' => 8, '7' => 7, '6' => 6, '5' => 5, '4' => 4, '3' => 3, '2' => 2 ];

        usort( $hand, function( $a, $b ) use ( $suit_order, $rank_order ) {
            $suit_diff = $suit_order[ $a['suit'] ] - $suit_order[ $b['suit'] ];
            if ( $suit_diff !== 0 ) return $suit_diff;
            return $rank_order[ $b['rank'] ] - $rank_order[ $a['rank'] ];
        });

        return $hand;
    }

    /**
     * Find player with 2 of clubs
     */
    private function find_two_of_clubs( array $hands ): int {
        foreach ( $hands as $seat => $hand ) {
            foreach ( $hand as $card ) {
                if ( $card['suit'] === 'clubs' && $card['rank'] === '2' ) {
                    return $seat;
                }
            }
        }
        return 0;
    }

    /**
     * Validate move
     */
    public function validate_move( array $state, int $player_seat, array $move ) {
        if ( $state['phase'] === 'passing' ) {
            return $this->validate_pass( $state, $player_seat, $move );
        }

        if ( $state['phase'] === 'playing' ) {
            return $this->validate_play( $state, $player_seat, $move );
        }

        return new WP_Error( 'invalid_phase', 'Cannot make moves in this phase.' );
    }

    /**
     * Validate pass selection
     */
    private function validate_pass( array $state, int $player_seat, array $move ) {
        if ( $state['passes_complete'][ $player_seat ] ) {
            return new WP_Error( 'already_passed', 'You have already selected cards to pass.' );
        }

        $cards = $move['cards'] ?? [];
        if ( count( $cards ) !== 3 ) {
            return new WP_Error( 'invalid_pass', 'You must select exactly 3 cards to pass.' );
        }

        $hand = $state['hands'][ $player_seat ];
        $hand_ids = array_column( $hand, 'id' );

        foreach ( $cards as $card_id ) {
            if ( ! in_array( $card_id, $hand_ids, true ) ) {
                return new WP_Error( 'invalid_card', 'You do not have that card.' );
            }
        }

        return true;
    }

    /**
     * Validate card play
     */
    private function validate_play( array $state, int $player_seat, array $move ) {
        if ( $state['current_turn'] !== $player_seat ) {
            return new WP_Error( 'not_your_turn', 'It is not your turn.' );
        }

        $card_id = $move['card_id'] ?? null;
        if ( ! $card_id ) {
            return new WP_Error( 'no_card', 'No card specified.' );
        }

        $hand = $state['hands'][ $player_seat ];
        $card = $this->find_card_in_hand( $hand, $card_id );

        if ( ! $card ) {
            return new WP_Error( 'invalid_card', 'You do not have that card.' );
        }

        // First trick must start with 2 of clubs
        if ( empty( $state['trick'] ) && $state['tricks_taken'] === [ 0, 0, 0, 0 ] ) {
            if ( $card['suit'] !== 'clubs' || $card['rank'] !== '2' ) {
                if ( $this->has_card( $hand, 'clubs', '2' ) ) {
                    return new WP_Error( 'must_lead_2c', 'You must lead the 2 of clubs.' );
                }
            }
        }

        // Must follow suit if possible
        if ( ! empty( $state['trick'] ) ) {
            $lead_suit = $state['trick'][0]['card']['suit'];
            $has_suit = $this->has_suit( $hand, $lead_suit );

            if ( $has_suit && $card['suit'] !== $lead_suit ) {
                return new WP_Error( 'must_follow', 'You must follow suit.' );
            }
        }

        // Cannot lead hearts until broken (unless only hearts left)
        if ( empty( $state['trick'] ) && ! $state['hearts_broken'] ) {
            if ( $card['suit'] === 'hearts' && ! $this->only_has_hearts( $hand ) ) {
                return new WP_Error( 'hearts_not_broken', 'Hearts have not been broken yet.' );
            }
        }

        // Cannot play points on first trick
        if ( $state['tricks_taken'] === [ 0, 0, 0, 0 ] ) {
            if ( empty( $state['trick'] ) ) {
                // Leading - 2 of clubs already checked
            } else {
                // Following - cannot play hearts or QoS unless forced
                $lead_suit = $state['trick'][0]['card']['suit'];
                if ( ! $this->has_suit( $hand, $lead_suit ) ) {
                    // Can't follow suit
                    if ( $this->is_point_card( $card ) ) {
                        $non_point = $this->has_non_point_card( $hand );
                        if ( $non_point ) {
                            return new WP_Error( 'no_points_first', 'Cannot play point cards on the first trick.' );
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * Apply move
     */
    public function apply_move( array $state, int $player_seat, array $move ): array {
        if ( $state['phase'] === 'passing' ) {
            return $this->apply_pass( $state, $player_seat, $move );
        }

        if ( $state['phase'] === 'playing' ) {
            return $this->apply_play( $state, $player_seat, $move );
        }

        return $state;
    }

    /**
     * Apply pass selection
     */
    private function apply_pass( array $state, int $player_seat, array $move ): array {
        $state['pass_selections'][ $player_seat ] = $move['cards'];
        $state['passes_complete'][ $player_seat ] = true;

        // Check if all passes are complete
        if ( ! in_array( false, $state['passes_complete'], true ) ) {
            $state = $this->execute_passes( $state );
            $state['phase'] = 'playing';
            $state['current_turn'] = $this->find_two_of_clubs( $state['hands'] );
            $state['trick_leader'] = $state['current_turn'];
        }

        return $state;
    }

    /**
     * Execute the card passing
     */
    private function execute_passes( array $state ): array {
        $direction = $state['pass_direction'];
        $offsets = [
            'left'   => 1,
            'right'  => 3,
            'across' => 2,
        ];
        $offset = $offsets[ $direction ];

        $new_hands = $state['hands'];

        for ( $seat = 0; $seat < 4; $seat++ ) {
            $target = ( $seat + $offset ) % 4;
            $passing = $state['pass_selections'][ $seat ];

            // Remove cards from giver
            $new_hands[ $seat ] = array_filter( $new_hands[ $seat ], function( $card ) use ( $passing ) {
                return ! in_array( $card['id'], $passing, true );
            });

            // Add cards to receiver
            foreach ( $passing as $card_id ) {
                $card = $this->find_card_by_id( $state['hands'][ $seat ], $card_id );
                if ( $card ) {
                    $new_hands[ $target ][] = $card;
                }
            }
        }

        // Re-index and sort
        foreach ( $new_hands as $seat => $hand ) {
            $new_hands[ $seat ] = $this->sort_hand( array_values( $hand ) );
        }

        $state['hands'] = $new_hands;
        return $state;
    }

    /**
     * Apply card play
     */
    private function apply_play( array $state, int $player_seat, array $move ): array {
        $card_id = $move['card_id'];
        $hand = $state['hands'][ $player_seat ];
        $card = $this->find_card_in_hand( $hand, $card_id );

        // Remove card from hand
        $state['hands'][ $player_seat ] = array_values( array_filter( $hand, function( $c ) use ( $card_id ) {
            return $c['id'] !== $card_id;
        }));

        // Add to trick
        $state['trick'][] = [
            'seat' => $player_seat,
            'card' => $card,
        ];

        // Check if hearts broken
        if ( $card['suit'] === 'hearts' && ! $state['hearts_broken'] ) {
            $state['hearts_broken'] = true;
        }

        // Trick complete?
        if ( count( $state['trick'] ) === 4 ) {
            $state = $this->resolve_trick( $state );
        }

        return $state;
    }

    /**
     * Resolve completed trick
     */
    private function resolve_trick( array $state ): array {
        $lead_suit = $state['trick'][0]['card']['suit'];
        $winner_seat = $state['trick'][0]['seat'];
        $winner_value = $this->get_card_value( $state['trick'][0]['card'] );

        // Find winner (highest card of lead suit)
        foreach ( $state['trick'] as $play ) {
            if ( $play['card']['suit'] === $lead_suit ) {
                $value = $this->get_card_value( $play['card'] );
                if ( $value > $winner_value ) {
                    $winner_value = $value;
                    $winner_seat = $play['seat'];
                }
            }
        }

        // Count points in trick
        $points = 0;
        $hearts_count = 0;
        foreach ( $state['trick'] as $play ) {
            if ( $play['card']['suit'] === 'hearts' ) {
                $points++;
                $hearts_count++;
            }
            if ( $play['card']['suit'] === 'spades' && $play['card']['rank'] === 'Q' ) {
                $points += 13;
            }
        }

        $state['tricks_taken'][ $winner_seat ]++;
        $state['hearts_in_trick'][ $winner_seat ] += $hearts_count;
        $state['round_scores'][ $winner_seat ] += $points;

        // Clear trick
        $state['trick'] = [];
        $state['trick_leader'] = $winner_seat;

        // Check if round is over
        if ( $this->is_round_over( $state ) ) {
            $state['phase'] = 'round_end';
            $state = $this->score_round( $state );
        }

        return $state;
    }

    /**
     * Check if round is over
     */
    private function is_round_over( array $state ): bool {
        foreach ( $state['hands'] as $hand ) {
            if ( ! empty( $hand ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Advance turn
     */
    public function advance_turn( array $state ): array {
        if ( $state['phase'] === 'playing' ) {
            if ( empty( $state['trick'] ) ) {
                // New trick - leader plays
                $state['current_turn'] = $state['trick_leader'];
            } else {
                // Continue trick
                $state['current_turn'] = ( $state['current_turn'] + 1 ) % 4;
            }
        }

        return $state;
    }

    /**
     * Check end condition
     */
    public function check_end_condition( array $state ): array {
        if ( $state['phase'] !== 'round_end' ) {
            return [ 'ended' => false, 'reason' => null, 'winners' => null ];
        }

        // Check if anyone has reached losing score
        foreach ( $state['total_scores'] as $seat => $score ) {
            if ( $score >= self::POINTS_TO_LOSE ) {
                // Find winner (lowest score)
                $min_score = min( $state['total_scores'] );
                $winners = array_keys( array_filter( $state['total_scores'], fn( $s ) => $s === $min_score ) );

                return [
                    'ended'   => true,
                    'reason'  => 'score_limit',
                    'winners' => $winners,
                ];
            }
        }

        return [ 'ended' => false, 'reason' => null, 'winners' => null ];
    }

    /**
     * Score round
     */
    public function score_round( array $state ): array {
        // Check for shoot the moon
        for ( $seat = 0; $seat < 4; $seat++ ) {
            if ( $state['round_scores'][ $seat ] === 26 ) {
                // Shot the moon!
                $state['round_scores'] = [ 26, 26, 26, 26 ];
                $state['round_scores'][ $seat ] = 0;
                break;
            }
        }

        // Add round scores to totals
        foreach ( $state['round_scores'] as $seat => $score ) {
            $state['total_scores'][ $seat ] += $score;
        }

        return $state;
    }

    /**
     * AI move
     */
    public function ai_move( array $state, int $player_seat, string $difficulty = 'beginner' ): array {
        if ( $state['phase'] === 'passing' ) {
            return $this->ai_pass( $state, $player_seat, $difficulty );
        }

        return $this->ai_play( $state, $player_seat, $difficulty );
    }

    /**
     * AI pass selection
     */
    private function ai_pass( array $state, int $player_seat, string $difficulty ): array {
        $hand = $state['hands'][ $player_seat ];

        // Strategy: pass high cards, especially spades and hearts
        $scored = [];
        foreach ( $hand as $card ) {
            $score = $this->get_card_value( $card );

            // Prefer passing QoS
            if ( $card['suit'] === 'spades' && $card['rank'] === 'Q' ) {
                $score += 20;
            }
            // High spades are dangerous
            if ( $card['suit'] === 'spades' && in_array( $card['rank'], [ 'A', 'K' ], true ) ) {
                $score += 10;
            }
            // High hearts
            if ( $card['suit'] === 'hearts' ) {
                $score += 5;
            }

            $scored[] = [ 'card' => $card, 'score' => $score ];
        }

        // Sort by score descending
        usort( $scored, fn( $a, $b ) => $b['score'] - $a['score'] );

        // Pass top 3
        $to_pass = array_slice( $scored, 0, 3 );

        return [
            'cards' => array_map( fn( $s ) => $s['card']['id'], $to_pass ),
        ];
    }

    /**
     * AI card play
     */
    private function ai_play( array $state, int $player_seat, string $difficulty ): array {
        $hand = $state['hands'][ $player_seat ];
        $valid = $this->get_valid_moves( $state, $player_seat );

        if ( empty( $valid ) ) {
            return [];
        }

        $ai_engine = new CGA_AI_Engine();

        // Beginner: mostly random
        if ( $difficulty === 'beginner' && ! $ai_engine->should_play_optimal( $difficulty ) ) {
            $move = $ai_engine->pick_random_move( $valid );
            return [ 'card_id' => $move['card_id'] ];
        }

        // Smarter play
        $trick = $state['trick'];
        $is_leading = empty( $trick );

        if ( $is_leading ) {
            // Lead lowest non-point card if possible
            $best = $this->find_best_lead( $hand, $state );
            return [ 'card_id' => $best['id'] ];
        } else {
            // Following
            $lead_suit = $trick[0]['card']['suit'];
            $best = $this->find_best_follow( $hand, $trick, $lead_suit, $state, $player_seat );
            return [ 'card_id' => $best['id'] ];
        }
    }

    /**
     * Find best card to lead
     */
    private function find_best_lead( array $hand, array $state ): array {
        // Prefer low clubs or diamonds
        $safe = array_filter( $hand, fn( $c ) => $c['suit'] === 'clubs' || $c['suit'] === 'diamonds' );

        if ( ! empty( $safe ) ) {
            usort( $safe, fn( $a, $b ) => $this->get_card_value( $a ) - $this->get_card_value( $b ) );
            return $safe[0];
        }

        // Low spade if no QoS danger
        $spades = array_filter( $hand, fn( $c ) => $c['suit'] === 'spades' );
        $has_qos = $this->has_card( $hand, 'spades', 'Q' );

        if ( ! empty( $spades ) && ! $has_qos ) {
            usort( $spades, fn( $a, $b ) => $this->get_card_value( $a ) - $this->get_card_value( $b ) );
            return $spades[0];
        }

        // Lead a heart to break if needed
        if ( $state['hearts_broken'] ) {
            $hearts = array_filter( $hand, fn( $c ) => $c['suit'] === 'hearts' );
            if ( ! empty( $hearts ) ) {
                usort( $hearts, fn( $a, $b ) => $this->get_card_value( $a ) - $this->get_card_value( $b ) );
                return $hearts[0];
            }
        }

        // Just play lowest
        usort( $hand, fn( $a, $b ) => $this->get_card_value( $a ) - $this->get_card_value( $b ) );
        return $hand[0];
    }

    /**
     * Find best card to follow with
     */
    private function find_best_follow( array $hand, array $trick, string $lead_suit, array $state, int $seat ): array {
        $suit_cards = array_filter( $hand, fn( $c ) => $c['suit'] === $lead_suit );

        if ( ! empty( $suit_cards ) ) {
            // Must follow suit
            // Try to play under if possible, else dump highest
            $current_winner = $this->get_trick_winner_value( $trick, $lead_suit );

            $under = array_filter( $suit_cards, fn( $c ) => $this->get_card_value( $c ) < $current_winner );

            if ( ! empty( $under ) ) {
                // Play highest that's still under
                usort( $under, fn( $a, $b ) => $this->get_card_value( $b ) - $this->get_card_value( $a ) );
                return $under[0];
            }

            // Must win trick - play highest to maximize chance of not winning next
            usort( $suit_cards, fn( $a, $b ) => $this->get_card_value( $b ) - $this->get_card_value( $a ) );
            return $suit_cards[0];
        }

        // Can't follow suit - dump points!
        // QoS first
        if ( $this->has_card( $hand, 'spades', 'Q' ) ) {
            return $this->find_card_in_hand( $hand, 'Q_spades' );
        }

        // High hearts
        $hearts = array_filter( $hand, fn( $c ) => $c['suit'] === 'hearts' );
        if ( ! empty( $hearts ) ) {
            usort( $hearts, fn( $a, $b ) => $this->get_card_value( $b ) - $this->get_card_value( $a ) );
            return $hearts[0];
        }

        // High spades (might drop QoS on someone)
        $spades = array_filter( $hand, fn( $c ) => $c['suit'] === 'spades' );
        if ( ! empty( $spades ) ) {
            usort( $spades, fn( $a, $b ) => $this->get_card_value( $b ) - $this->get_card_value( $a ) );
            return $spades[0];
        }

        // Just play highest
        usort( $hand, fn( $a, $b ) => $this->get_card_value( $b ) - $this->get_card_value( $a ) );
        return $hand[0];
    }

    /**
     * Get current winning value in trick
     */
    private function get_trick_winner_value( array $trick, string $lead_suit ): int {
        $max = 0;
        foreach ( $trick as $play ) {
            if ( $play['card']['suit'] === $lead_suit ) {
                $val = $this->get_card_value( $play['card'] );
                if ( $val > $max ) $max = $val;
            }
        }
        return $max;
    }

    /**
     * Get valid moves
     */
    public function get_valid_moves( array $state, int $player_seat ): array {
        if ( $state['phase'] === 'passing' ) {
            // Return all cards as selectable
            return array_map( fn( $c ) => [ 'card_id' => $c['id'] ], $state['hands'][ $player_seat ] );
        }

        if ( $state['phase'] !== 'playing' || $state['current_turn'] !== $player_seat ) {
            return [];
        }

        $hand = $state['hands'][ $player_seat ];
        $valid = [];

        foreach ( $hand as $card ) {
            $move = [ 'card_id' => $card['id'] ];
            $result = $this->validate_play( $state, $player_seat, $move );
            if ( $result === true ) {
                $valid[] = $move;
            }
        }

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
                $public['hands'][ $seat ] = count( $hand ); // Just show count
            }
        }

        // Hide pass selections until complete
        if ( $state['phase'] === 'passing' ) {
            $public['pass_selections'] = [];
            foreach ( $state['pass_selections'] as $seat => $selection ) {
                if ( $seat === $player_seat ) {
                    $public['pass_selections'][ $seat ] = $selection;
                } else {
                    $public['pass_selections'][ $seat ] = $state['passes_complete'][ $seat ] ? 'ready' : 'selecting';
                }
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

    private function find_card_by_id( array $hand, string $card_id ): ?array {
        return $this->find_card_in_hand( $hand, $card_id );
    }

    private function has_card( array $hand, string $suit, string $rank ): bool {
        foreach ( $hand as $card ) {
            if ( $card['suit'] === $suit && $card['rank'] === $rank ) {
                return true;
            }
        }
        return false;
    }

    private function has_suit( array $hand, string $suit ): bool {
        foreach ( $hand as $card ) {
            if ( $card['suit'] === $suit ) {
                return true;
            }
        }
        return false;
    }

    private function only_has_hearts( array $hand ): bool {
        foreach ( $hand as $card ) {
            if ( $card['suit'] !== 'hearts' ) {
                return false;
            }
        }
        return true;
    }

    private function is_point_card( array $card ): bool {
        if ( $card['suit'] === 'hearts' ) return true;
        if ( $card['suit'] === 'spades' && $card['rank'] === 'Q' ) return true;
        return false;
    }

    private function has_non_point_card( array $hand ): bool {
        foreach ( $hand as $card ) {
            if ( ! $this->is_point_card( $card ) ) {
                return true;
            }
        }
        return false;
    }

    protected function get_card_value( array $card ): int {
        $values = [
            '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7,
            '8' => 8, '9' => 9, '10' => 10, 'J' => 11, 'Q' => 12, 'K' => 13, 'A' => 14,
        ];
        return $values[ $card['rank'] ] ?? 0;
    }
}
