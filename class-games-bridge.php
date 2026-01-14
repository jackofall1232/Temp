<?php
/**
 * Bridge Game Module
 *
 * Classic 4-player partnership Bridge
 * - Bidding phase to determine contract
 * - Trick-taking gameplay
 * - Strict rules (no house-rule gameplay changes)
 * - Support for different bidding systems
 * - Duplicate scoring support
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CGA_Game_Bridge extends CGA_Game_Contract {

    protected $id = 'bridge';
    protected $name = 'Bridge';
    protected $type = 'card';
    protected $min_players = 4;
    protected $max_players = 4;
    protected $has_teams = true;
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
            'description'  => 'Partnership trick-taking game with bidding. Bid to make your contract!',
        ];
    }

    /**
     * Initialize game state
     */
    public function init_state( array $players, array $settings = [] ): array {
        $bidding_system = $settings['bidding_system'] ?? 'standard_american';

        return [
            'phase'            => 'bidding', // bidding, playing, hand_end
            'current_turn'     => 0,
            'dealer'           => 0,
            'current_bidder'   => 1, // Player left of dealer opens
            'players'          => $this->format_players( $players ),
            'hands'            => [],
            'bidding_history'  => [],
            'current_contract' => null,
            'declarer'         => null,
            'dummy_seat'       => null,
            'dummy_revealed'   => false,
            'doubled'          => false,
            'redoubled'        => false,
            'contract_level'   => 0,
            'contract_suit'    => null,
            'trick'            => [],
            'trick_leader'     => 0,
            'tricks_ns'        => 0, // North/South tricks
            'tricks_ew'        => 0, // East/West tricks
            'vulnerability'    => 'none', // none, ns, ew, both
            'hand_score'       => 0,
            'total_score_ns'   => 0,
            'total_score_ew'   => 0,
            'hand_number'      => 1,
            'bidding_system'   => $bidding_system,
            'passes_in_row'    => 0,
            'game_over'        => false,
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

        // Sort hands by suit
        foreach ( $state['hands'] as $seat => $hand ) {
            $state['hands'][ $seat ] = $this->sort_hand_by_suit( $hand );
        }

        // Set dealer and first bidder
        $state['dealer'] = ( $state['hand_number'] - 1 ) % 4;
        $state['current_bidder'] = ( $state['dealer'] + 1 ) % 4;

        // Set vulnerability based on hand number (rotating)
        $vul_cycle = [ 'none', 'ns', 'ew', 'both' ];
        $state['vulnerability'] = $vul_cycle[ ( $state['hand_number'] - 1 ) % 4 ];

        // Reset hand state
        $state['phase'] = 'bidding';
        $state['bidding_history'] = [];
        $state['current_contract'] = null;
        $state['declarer'] = null;
        $state['dummy_seat'] = null;
        $state['dummy_revealed'] = false;
        $state['doubled'] = false;
        $state['redoubled'] = false;
        $state['trick'] = [];
        $state['tricks_ns'] = 0;
        $state['tricks_ew'] = 0;
        $state['passes_in_row'] = 0;

        return $state;
    }

    /**
     * Sort hand by suit (Bridge standard: Spades, Hearts, Diamonds, Clubs)
     */
    private function sort_hand_by_suit( array $hand ): array {
        $suit_order = [ 'spades' => 0, 'hearts' => 1, 'diamonds' => 2, 'clubs' => 3 ];
        $rank_order = [ 'A' => 14, 'K' => 13, 'Q' => 12, 'J' => 11, '10' => 10, '9' => 9,
                        '8' => 8, '7' => 7, '6' => 6, '5' => 5, '4' => 4, '3' => 3, '2' => 2 ];

        usort( $hand, function( $a, $b ) use ( $suit_order, $rank_order ) {
            $suit_diff = $suit_order[ $a['suit'] ] - $suit_order[ $b['suit'] ];
            if ( $suit_diff !== 0 ) return $suit_diff;
            return $rank_order[ $b['rank'] ] - $rank_order[ $a['rank'] ];
        });

        return $hand;
    }

    /**
     * Validate move
     */
    public function validate_move( array $state, int $player_seat, array $move ) {
        if ( $state['phase'] === 'bidding' ) {
            return $this->validate_bid( $state, $player_seat, $move );
        }

        if ( $state['phase'] === 'playing' ) {
            return $this->validate_play( $state, $player_seat, $move );
        }

        return new WP_Error( 'invalid_phase', 'Cannot make moves in this phase.' );
    }

    /**
     * Validate bid
     */
    private function validate_bid( array $state, int $player_seat, array $move ) {
        if ( $state['current_bidder'] !== $player_seat ) {
            return new WP_Error( 'not_your_turn', 'It is not your turn to bid.' );
        }

        $bid_type = $move['bid_type'] ?? null;

        if ( $bid_type === 'pass' ) {
            return true;
        }

        if ( $bid_type === 'double' ) {
            // Can only double opponent's bid
            if ( empty( $state['bidding_history'] ) ) {
                return new WP_Error( 'cannot_double', 'No bid to double.' );
            }

            $last_bid = end( $state['bidding_history'] );
            if ( $last_bid['bid']['type'] !== 'bid' ) {
                return new WP_Error( 'cannot_double', 'Can only double a bid.' );
            }

            // Check if opponent made the bid
            $bidder_team = $last_bid['seat'] % 2;
            $my_team = $player_seat % 2;

            if ( $bidder_team === $my_team ) {
                return new WP_Error( 'cannot_double', 'Cannot double partner\'s bid.' );
            }

            if ( $state['doubled'] ) {
                return new WP_Error( 'already_doubled', 'Bid already doubled.' );
            }

            return true;
        }

        if ( $bid_type === 'redouble' ) {
            if ( ! $state['doubled'] ) {
                return new WP_Error( 'not_doubled', 'No double to redouble.' );
            }

            if ( $state['redoubled'] ) {
                return new WP_Error( 'already_redoubled', 'Already redoubled.' );
            }

            return true;
        }

        if ( $bid_type === 'bid' ) {
            $level = $move['level'] ?? null;
            $suit = $move['suit'] ?? null;

            if ( ! $level || ! $suit ) {
                return new WP_Error( 'invalid_bid', 'Bid must have level and suit.' );
            }

            if ( $level < 1 || $level > 7 ) {
                return new WP_Error( 'invalid_level', 'Level must be between 1 and 7.' );
            }

            $valid_suits = [ 'clubs', 'diamonds', 'hearts', 'spades', 'notrump' ];
            if ( ! in_array( $suit, $valid_suits, true ) ) {
                return new WP_Error( 'invalid_suit', 'Invalid suit.' );
            }

            // Must be higher than current contract
            if ( $state['current_contract'] ) {
                $current = $state['current_contract'];
                $bid_value = $this->get_bid_value( $level, $suit );
                $current_value = $this->get_bid_value( $current['level'], $current['suit'] );

                if ( $bid_value <= $current_value ) {
                    return new WP_Error( 'insufficient_bid', 'Bid must be higher than current contract.' );
                }
            }

            return true;
        }

        return new WP_Error( 'invalid_bid_type', 'Invalid bid type.' );
    }

    /**
     * Get numeric value of bid for comparison
     */
    private function get_bid_value( int $level, string $suit ): int {
        $suit_values = [
            'clubs'    => 0,
            'diamonds' => 1,
            'hearts'   => 2,
            'spades'   => 3,
            'notrump'  => 4,
        ];

        return ( $level * 5 ) + $suit_values[ $suit ];
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

        // Must follow suit if possible
        if ( ! empty( $state['trick'] ) ) {
            $lead_suit = $state['trick'][0]['card']['suit'];
            $has_suit = $this->has_suit( $hand, $lead_suit );

            if ( $has_suit && $card['suit'] !== $lead_suit ) {
                return new WP_Error( 'must_follow', 'You must follow suit.' );
            }
        }

        return true;
    }

    /**
     * Apply move
     */
    public function apply_move( array $state, int $player_seat, array $move ): array {
        if ( $state['phase'] === 'bidding' ) {
            return $this->apply_bid( $state, $player_seat, $move );
        }

        if ( $state['phase'] === 'playing' ) {
            return $this->apply_play( $state, $player_seat, $move );
        }

        return $state;
    }

    /**
     * Apply bid
     */
    private function apply_bid( array $state, int $player_seat, array $move ): array {
        $bid_type = $move['bid_type'];

        $bid_record = [
            'seat' => $player_seat,
            'bid'  => $move,
        ];

        $state['bidding_history'][] = $bid_record;

        if ( $bid_type === 'pass' ) {
            $state['passes_in_row']++;

            // If all 4 pass initially, redeal
            if ( count( $state['bidding_history'] ) === 4 && $state['passes_in_row'] === 4 ) {
                return $this->deal_or_setup( $state );
            }

            // If 3 passes after a bid, bidding ends
            if ( $state['current_contract'] && $state['passes_in_row'] === 3 ) {
                $state['phase'] = 'playing';
                $state['trick_leader'] = ( $state['dealer'] + 1 ) % 4;
                $state['current_turn'] = $state['trick_leader'];
                return $state;
            }
        } elseif ( $bid_type === 'double' ) {
            $state['doubled'] = true;
            $state['passes_in_row'] = 0;
        } elseif ( $bid_type === 'redouble' ) {
            $state['redoubled'] = true;
            $state['passes_in_row'] = 0;
        } elseif ( $bid_type === 'bid' ) {
            $state['current_contract'] = [
                'level' => $move['level'],
                'suit'  => $move['suit'],
            ];
            $state['contract_level'] = $move['level'];
            $state['contract_suit'] = $move['suit'];
            $state['declarer'] = $player_seat;
            $state['doubled'] = false;
            $state['redoubled'] = false;
            $state['passes_in_row'] = 0;
        }

        // Advance to next bidder
        $state['current_bidder'] = ( $state['current_bidder'] + 1 ) % 4;

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

        // Reveal dummy after first lead
        if ( count( $state['trick'] ) === 1 && ! $state['dummy_revealed'] ) {
            $state['dummy_seat'] = ( $state['declarer'] + 2 ) % 4; // Partner of declarer
            $state['dummy_revealed'] = true;
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
        $trump = $state['contract_suit'] === 'notrump' ? null : $state['contract_suit'];
        $lead_suit = $state['trick'][0]['card']['suit'];

        $winner_seat = $state['trick'][0]['seat'];
        $winner_value = $this->get_card_value( $state['trick'][0]['card'] );
        $winner_is_trump = ( $trump && $state['trick'][0]['card']['suit'] === $trump );

        // Find winner (highest trump, or highest card of lead suit)
        foreach ( $state['trick'] as $play ) {
            $card = $play['card'];
            $value = $this->get_card_value( $card );
            $is_trump = ( $trump && $card['suit'] === $trump );

            if ( $is_trump && ! $winner_is_trump ) {
                // Trump beats non-trump
                $winner_seat = $play['seat'];
                $winner_value = $value;
                $winner_is_trump = true;
            } elseif ( $is_trump && $winner_is_trump && $value > $winner_value ) {
                // Higher trump
                $winner_seat = $play['seat'];
                $winner_value = $value;
            } elseif ( ! $is_trump && ! $winner_is_trump && $card['suit'] === $lead_suit && $value > $winner_value ) {
                // Higher card of lead suit
                $winner_seat = $play['seat'];
                $winner_value = $value;
            }
        }

        // Award trick to winning team
        if ( $winner_seat % 2 === 0 ) {
            $state['tricks_ns']++;
        } else {
            $state['tricks_ew']++;
        }

        // Clear trick
        $state['trick'] = [];
        $state['trick_leader'] = $winner_seat;

        // Check if hand is over
        if ( $this->is_hand_over( $state ) ) {
            $state['phase'] = 'hand_end';
            $state = $this->score_hand( $state );
        }

        return $state;
    }

    /**
     * Check if hand is over
     */
    private function is_hand_over( array $state ): bool {
        return $state['tricks_ns'] + $state['tricks_ew'] === 13;
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
     * Score hand
     */
    public function score_hand( array $state ): array {
        $declarer_team = $state['declarer'] % 2;
        $tricks_won = $declarer_team === 0 ? $state['tricks_ns'] : $state['tricks_ew'];
        $contract_level = $state['contract_level'];
        $contract_suit = $state['contract_suit'];
        $is_vulnerable = $this->is_team_vulnerable( $state, $declarer_team );

        $tricks_needed = 6 + $contract_level;
        $tricks_made = $tricks_won - 6;

        $score = 0;

        if ( $tricks_made >= $contract_level ) {
            // Made contract
            $base_score = $this->calculate_trick_score( $contract_level, $contract_suit );

            if ( $state['doubled'] ) {
                $base_score *= 2;
            }
            if ( $state['redoubled'] ) {
                $base_score *= 4;
            }

            // Overtricks
            $overtricks = $tricks_made - $contract_level;
            $overtrick_value = $this->calculate_overtrick_score( $overtricks, $contract_suit, $state['doubled'], $state['redoubled'], $is_vulnerable );

            $score = $base_score + $overtrick_value;

            // Game bonus (simplified)
            if ( $base_score >= 100 ) {
                $score += $is_vulnerable ? 500 : 300;
            }

            // Slam bonuses
            if ( $contract_level === 6 ) {
                $score += $is_vulnerable ? 750 : 500; // Small slam
            } elseif ( $contract_level === 7 ) {
                $score += $is_vulnerable ? 1500 : 1000; // Grand slam
            }
        } else {
            // Failed contract
            $undertricks = $contract_level - $tricks_made;
            $score = -$this->calculate_undertrick_penalty( $undertricks, $state['doubled'], $state['redoubled'], $is_vulnerable );
        }

        $state['hand_score'] = $score;

        // Add to team total
        if ( $declarer_team === 0 ) {
            $state['total_score_ns'] += $score;
        } else {
            $state['total_score_ew'] += $score;
        }

        return $state;
    }

    /**
     * Calculate trick score
     */
    private function calculate_trick_score( int $level, string $suit ): int {
        $per_trick = [
            'clubs'    => 20,
            'diamonds' => 20,
            'hearts'   => 30,
            'spades'   => 30,
            'notrump'  => 30, // First trick 40, rest 30
        ];

        if ( $suit === 'notrump' ) {
            return 40 + ( ( $level - 1 ) * 30 );
        }

        return $level * $per_trick[ $suit ];
    }

    /**
     * Calculate overtrick score (simplified)
     */
    private function calculate_overtrick_score( int $overtricks, string $suit, bool $doubled, bool $redoubled, bool $vulnerable ): int {
        if ( $doubled || $redoubled ) {
            $per_trick = $vulnerable ? 200 : 100;
            if ( $redoubled ) $per_trick *= 2;
            return $overtricks * $per_trick;
        }

        $per_trick = in_array( $suit, [ 'clubs', 'diamonds' ], true ) ? 20 : 30;
        return $overtricks * $per_trick;
    }

    /**
     * Calculate undertrick penalty (simplified)
     */
    private function calculate_undertrick_penalty( int $undertricks, bool $doubled, bool $redoubled, bool $vulnerable ): int {
        if ( ! $doubled && ! $redoubled ) {
            return $undertricks * ( $vulnerable ? 100 : 50 );
        }

        // Simplified doubled/redoubled penalties
        $penalty = $vulnerable ? 200 : 100;
        if ( $redoubled ) $penalty *= 2;

        return $undertricks * $penalty;
    }

    /**
     * Check if team is vulnerable
     */
    private function is_team_vulnerable( array $state, int $team ): bool {
        $vul = $state['vulnerability'];

        if ( $vul === 'both' ) return true;
        if ( $vul === 'none' ) return false;
        if ( $vul === 'ns' && $team === 0 ) return true;
        if ( $vul === 'ew' && $team === 1 ) return true;

        return false;
    }

    /**
     * Check end condition
     */
    public function check_end_condition( array $state ): array {
        // Bridge typically plays multiple hands - this is simplified
        // In a real implementation, would track rubbers or set number of hands

        return [ 'ended' => false, 'reason' => null, 'winners' => null ];
    }

    /**
     * AI move
     */
    public function ai_move( array $state, int $player_seat, string $difficulty = 'beginner' ): array {
        if ( $state['phase'] === 'bidding' ) {
            return $this->ai_bid( $state, $player_seat, $difficulty );
        }

        return $this->ai_play( $state, $player_seat, $difficulty );
    }

    /**
     * AI bidding (simplified)
     */
    private function ai_bid( array $state, int $player_seat, string $difficulty ): array {
        $hand = $state['hands'][ $player_seat ];

        // Count high card points (simplified)
        $hcp = 0;
        foreach ( $hand as $card ) {
            if ( $card['rank'] === 'A' ) $hcp += 4;
            elseif ( $card['rank'] === 'K' ) $hcp += 3;
            elseif ( $card['rank'] === 'Q' ) $hcp += 2;
            elseif ( $card['rank'] === 'J' ) $hcp += 1;
        }

        // Count suit distribution
        $suit_counts = [
            'spades'   => 0,
            'hearts'   => 0,
            'diamonds' => 0,
            'clubs'    => 0,
        ];

        foreach ( $hand as $card ) {
            if ( isset( $suit_counts[ $card['suit'] ] ) ) {
                $suit_counts[ $card['suit'] ]++;
            }
        }

        // Very simplified bidding logic
        // Opening bid requires 13+ HCP
        if ( empty( $state['bidding_history'] ) || $state['passes_in_row'] === 3 ) {
            if ( $hcp < 13 ) {
                return [ 'bid_type' => 'pass' ];
            }

            // Find longest suit
            arsort( $suit_counts );
            $longest_suit = array_key_first( $suit_counts );
            $longest_length = $suit_counts[ $longest_suit ];

            if ( $longest_length >= 5 ) {
                return [
                    'bid_type' => 'bid',
                    'level'    => 1,
                    'suit'     => $longest_suit,
                ];
            }

            // Balanced hand - bid 1NT
            if ( $hcp >= 15 && $hcp <= 17 ) {
                return [
                    'bid_type' => 'bid',
                    'level'    => 1,
                    'suit'     => 'notrump',
                ];
            }

            // Bid longest suit
            return [
                'bid_type' => 'bid',
                'level'    => 1,
                'suit'     => $longest_suit,
            ];
        }

        // Otherwise pass (simplified)
        return [ 'bid_type' => 'pass' ];
    }

    /**
     * AI card play (simplified)
     */
    private function ai_play( array $state, int $player_seat, string $difficulty ): array {
        $hand = $state['hands'][ $player_seat ];
        $valid = $this->get_valid_moves( $state, $player_seat );

        if ( empty( $valid ) ) {
            return [];
        }

        // Play random valid card (simplified)
        $random_move = $valid[ array_rand( $valid ) ];
        return [ 'card_id' => $random_move['card_id'] ];
    }

    /**
     * Get valid moves
     */
    public function get_valid_moves( array $state, int $player_seat ): array {
        if ( $state['phase'] === 'bidding' ) {
            // Simplified - would need full bidding logic
            return [];
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

        // Hide other players' hands (except dummy when revealed)
        $public['hands'] = [];
        foreach ( $state['hands'] as $seat => $hand ) {
            if ( $seat === $player_seat || ( $seat === $state['dummy_seat'] && $state['dummy_revealed'] ) ) {
                $public['hands'][ $seat ] = $hand;
            } else {
                $public['hands'][ $seat ] = is_array( $hand ) ? count( $hand ) : $hand;
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

    private function has_suit( array $hand, string $suit ): bool {
        foreach ( $hand as $card ) {
            if ( $card['suit'] === $suit ) {
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
