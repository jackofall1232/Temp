<?php
/**
 * Euchre Game Module
 * 
 * 4-player partnership Euchre
 * - 24 cards (9, 10, J, Q, K, A)
 * - Trump selection phase
 * - Right and Left bower (Jacks)
 * - First to 10 points wins
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CGA_Game_Euchre extends CGA_Game_Contract {

    protected $id = 'euchre';
    protected $name = 'Euchre';
    protected $type = 'card';
    protected $min_players = 4;
    protected $max_players = 4;
    protected $has_teams = true;
    protected $ai_supported = true;

    const WIN_SCORE = 10;

    public function register_game(): array {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'type'         => $this->type,
            'min_players'  => $this->min_players,
            'max_players'  => $this->max_players,
            'has_teams'    => $this->has_teams,
            'ai_supported' => $this->ai_supported,
            'description'  => 'Fast-paced trick-taking with a 24-card deck. Call trump and win tricks!',
        ];
    }

    public function init_state( array $players, array $settings = [] ): array {
        return [
            'phase'         => 'dealing',
            'current_turn'  => 0,
            'dealer'        => 0,
            'players'       => $this->format_players( $players ),
            'teams'         => [ [ 0, 2 ], [ 1, 3 ] ],
            'hands'         => [],
            'kitty'         => [],
            'turned_card'   => null,
            'trump'         => null,
            'caller'        => null,
            'going_alone'   => false,
            'trick'         => [],
            'trick_leader'  => 1,
            'tricks_won'    => [ 0, 0, 0, 0 ],
            'team_scores'   => [ 0, 0 ],
            'round_number'  => 1,
            'game_over'     => false,
        ];
    }

    private function format_players( array $players ): array {
        $formatted = [];
        foreach ( $players as $player ) {
            $seat = (int) $player['seat_position'];
            $formatted[ $seat ] = [
                'name'  => $player['display_name'],
                'is_ai' => (bool) $player['is_ai'],
                'team'  => $seat % 2,
            ];
        }
        return $formatted;
    }

    public function deal_or_setup( array $state ): array {
        $deck = $this->create_euchre_deck();
        $deck = $this->shuffle_deck( $deck );

        // Deal 5 cards each (Euchre style: 3-2 or 2-3)
        $state['hands'] = [ [], [], [], [] ];
        
        for ( $round = 0; $round < 2; $round++ ) {
            for ( $seat = 0; $seat < 4; $seat++ ) {
                $deal_seat = ( $state['dealer'] + 1 + $seat ) % 4;
                $count = ( $round === 0 ) ? 3 : 2;
                if ( $seat % 2 === 1 ) {
                    $count = ( $round === 0 ) ? 2 : 3;
                }
                
                for ( $i = 0; $i < $count; $i++ ) {
                    $state['hands'][ $deal_seat ][] = array_shift( $deck );
                }
            }
        }

        // Sort hands
        foreach ( $state['hands'] as $seat => $hand ) {
            $state['hands'][ $seat ] = $this->sort_hand( $hand );
        }

        // Turn up card
        $state['turned_card'] = array_shift( $deck );
        $state['kitty'] = $deck;

        $state['phase'] = 'calling_round1';
        $state['current_turn'] = ( $state['dealer'] + 1 ) % 4;
        $state['trump'] = null;
        $state['caller'] = null;
        $state['going_alone'] = false;
        $state['trick'] = [];
        $state['tricks_won'] = [ 0, 0, 0, 0 ];

        return $state;
    }

    private function create_euchre_deck(): array {
        $suits = [ 'hearts', 'diamonds', 'clubs', 'spades' ];
        $ranks = [ '9', '10', 'J', 'Q', 'K', 'A' ];
        $deck = [];

        foreach ( $suits as $suit ) {
            foreach ( $ranks as $rank ) {
                $deck[] = [
                    'suit' => $suit,
                    'rank' => $rank,
                    'id'   => "{$rank}_{$suit}",
                ];
            }
        }

        return $deck;
    }

    private function sort_hand( array $hand, ?string $trump = null ): array {
        $suit_order = [ 'spades' => 0, 'hearts' => 1, 'diamonds' => 2, 'clubs' => 3 ];
        
        usort( $hand, function( $a, $b ) use ( $suit_order, $trump ) {
            $a_val = $this->get_sort_value( $a, $trump );
            $b_val = $this->get_sort_value( $b, $trump );
            
            $suit_diff = $suit_order[ $a['suit'] ] - $suit_order[ $b['suit'] ];
            if ( $suit_diff !== 0 ) return $suit_diff;
            return $b_val - $a_val;
        });

        return $hand;
    }

    private function get_sort_value( array $card, ?string $trump ): int {
        $values = [ '9' => 9, '10' => 10, 'J' => 11, 'Q' => 12, 'K' => 13, 'A' => 14 ];
        return $values[ $card['rank'] ] ?? 0;
    }

    public function validate_move( array $state, int $player_seat, array $move ) {
        if ( $state['current_turn'] !== $player_seat ) {
            return new WP_Error( 'not_your_turn', 'It is not your turn.' );
        }

        $phase = $state['phase'];

        if ( $phase === 'calling_round1' ) {
            $action = $move['action'] ?? null;
            if ( $action === 'pass' ) return true;
            if ( $action === 'order_up' ) return true;
            if ( $action === 'order_up_alone' ) return true;
            return new WP_Error( 'invalid_action', 'Must pass or order up.' );
        }

        if ( $phase === 'calling_round2' ) {
            $action = $move['action'] ?? null;
            if ( $action === 'pass' ) {
                // Dealer cannot pass in round 2 (stick the dealer)
                if ( $player_seat === $state['dealer'] ) {
                    return new WP_Error( 'must_call', 'Dealer must call trump.' );
                }
                return true;
            }
            if ( $action === 'call' ) {
                $suit = $move['suit'] ?? null;
                if ( ! $suit || $suit === $state['turned_card']['suit'] ) {
                    return new WP_Error( 'invalid_suit', 'Must call a different suit.' );
                }
                return true;
            }
            return new WP_Error( 'invalid_action', 'Must pass or call trump.' );
        }

        if ( $phase === 'dealer_discard' ) {
            $card_id = $move['card_id'] ?? null;
            if ( ! $card_id ) {
                return new WP_Error( 'no_card', 'Must discard a card.' );
            }
            $hand = $state['hands'][ $player_seat ];
            if ( ! $this->find_card( $hand, $card_id ) ) {
                return new WP_Error( 'invalid_card', 'Card not in hand.' );
            }
            return true;
        }

        if ( $phase === 'playing' ) {
            $card_id = $move['card_id'] ?? null;
            if ( ! $card_id ) {
                return new WP_Error( 'no_card', 'No card specified.' );
            }

            $hand = $state['hands'][ $player_seat ];
            $card = $this->find_card( $hand, $card_id );

            if ( ! $card ) {
                return new WP_Error( 'invalid_card', 'Card not in hand.' );
            }

            // Must follow suit (accounting for left bower)
            if ( ! empty( $state['trick'] ) ) {
                $lead_card = $state['trick'][0]['card'];
                $lead_suit = $this->get_effective_suit( $lead_card, $state['trump'] );
                $card_suit = $this->get_effective_suit( $card, $state['trump'] );
                
                if ( $this->has_effective_suit( $hand, $lead_suit, $state['trump'] ) && $card_suit !== $lead_suit ) {
                    return new WP_Error( 'must_follow', 'You must follow suit.' );
                }
            }

            return true;
        }

        return new WP_Error( 'invalid_phase', 'Cannot make moves now.' );
    }

    private function get_effective_suit( array $card, ?string $trump ): string {
        if ( ! $trump ) return $card['suit'];
        
        // Left bower (jack of same color as trump) counts as trump
        if ( $card['rank'] === 'J' ) {
            $same_color = $this->get_same_color_suit( $trump );
            if ( $card['suit'] === $same_color ) {
                return $trump;
            }
        }
        
        return $card['suit'];
    }

    private function get_same_color_suit( string $suit ): string {
        $pairs = [
            'hearts' => 'diamonds',
            'diamonds' => 'hearts',
            'clubs' => 'spades',
            'spades' => 'clubs',
        ];
        return $pairs[ $suit ];
    }

    private function has_effective_suit( array $hand, string $suit, ?string $trump ): bool {
        foreach ( $hand as $card ) {
            if ( $this->get_effective_suit( $card, $trump ) === $suit ) {
                return true;
            }
        }
        return false;
    }

    public function apply_move( array $state, int $player_seat, array $move ): array {
        $phase = $state['phase'];

        if ( $phase === 'calling_round1' ) {
            $action = $move['action'];
            
            if ( $action === 'pass' ) {
                if ( $player_seat === $state['dealer'] ) {
                    // All passed, go to round 2
                    $state['phase'] = 'calling_round2';
                    $state['current_turn'] = ( $state['dealer'] + 1 ) % 4;
                }
                return $state;
            }

            // Order up
            $state['trump'] = $state['turned_card']['suit'];
            $state['caller'] = $player_seat;
            $state['going_alone'] = ( $action === 'order_up_alone' );
            
            // Dealer picks up the turned card
            $state['hands'][ $state['dealer'] ][] = $state['turned_card'];
            $state['phase'] = 'dealer_discard';
            $state['current_turn'] = $state['dealer'];
            
            return $state;
        }

        if ( $phase === 'calling_round2' ) {
            $action = $move['action'];
            
            if ( $action === 'pass' ) {
                return $state;
            }

            // Call trump
            $state['trump'] = $move['suit'];
            $state['caller'] = $player_seat;
            $state['going_alone'] = ! empty( $move['alone'] );
            $state['phase'] = 'playing';
            $state['trick_leader'] = ( $state['dealer'] + 1 ) % 4;
            $state['current_turn'] = $state['trick_leader'];
            
            // Re-sort hands with trump
            foreach ( $state['hands'] as $seat => $hand ) {
                $state['hands'][ $seat ] = $this->sort_hand( $hand, $state['trump'] );
            }
            
            return $state;
        }

        if ( $phase === 'dealer_discard' ) {
            $card_id = $move['card_id'];
            $hand = $state['hands'][ $player_seat ];
            
            $state['hands'][ $player_seat ] = array_values( array_filter( $hand, fn( $c ) => $c['id'] !== $card_id ) );
            $state['hands'][ $player_seat ] = $this->sort_hand( $state['hands'][ $player_seat ], $state['trump'] );
            
            $state['phase'] = 'playing';
            $state['trick_leader'] = ( $state['dealer'] + 1 ) % 4;
            $state['current_turn'] = $state['trick_leader'];
            
            // Re-sort all hands
            foreach ( $state['hands'] as $seat => $hand ) {
                $state['hands'][ $seat ] = $this->sort_hand( $hand, $state['trump'] );
            }
            
            return $state;
        }

        if ( $phase === 'playing' ) {
            $card_id = $move['card_id'];
            $hand = $state['hands'][ $player_seat ];
            $card = $this->find_card( $hand, $card_id );

            $state['hands'][ $player_seat ] = array_values( array_filter( $hand, fn( $c ) => $c['id'] !== $card_id ) );
            $state['trick'][] = [ 'seat' => $player_seat, 'card' => $card ];

            // Check trick completion (accounting for going alone)
            $players_in_round = $state['going_alone'] ? 3 : 4;
            
            if ( count( $state['trick'] ) >= $players_in_round ) {
                $state = $this->resolve_trick( $state );
            }
        }

        return $state;
    }

    private function resolve_trick( array $state ): array {
        $trump = $state['trump'];
        $lead_suit = $this->get_effective_suit( $state['trick'][0]['card'], $trump );
        
        $winner_seat = $state['trick'][0]['seat'];
        $winner_value = $this->get_trick_value( $state['trick'][0]['card'], $trump, $lead_suit );

        foreach ( $state['trick'] as $play ) {
            $value = $this->get_trick_value( $play['card'], $trump, $lead_suit );
            if ( $value > $winner_value ) {
                $winner_value = $value;
                $winner_seat = $play['seat'];
            }
        }

        $state['tricks_won'][ $winner_seat ]++;
        $state['trick'] = [];
        $state['trick_leader'] = $winner_seat;

        // Check if round is over (5 tricks played)
        $total_tricks = array_sum( $state['tricks_won'] );
        if ( $total_tricks >= 5 ) {
            $state['phase'] = 'round_end';
            $state = $this->score_round( $state );
        }

        return $state;
    }

    private function get_trick_value( array $card, string $trump, string $lead_suit ): int {
        $effective_suit = $this->get_effective_suit( $card, $trump );
        $is_trump = $effective_suit === $trump;
        $follows_suit = $effective_suit === $lead_suit;
        
        $base_values = [ '9' => 1, '10' => 2, 'J' => 3, 'Q' => 4, 'K' => 5, 'A' => 6 ];
        $value = $base_values[ $card['rank'] ] ?? 0;

        // Right bower (jack of trump)
        if ( $card['rank'] === 'J' && $card['suit'] === $trump ) {
            return 100;
        }

        // Left bower (jack of same color)
        if ( $card['rank'] === 'J' && $card['suit'] === $this->get_same_color_suit( $trump ) ) {
            return 99;
        }

        // Trump cards beat non-trump
        if ( $is_trump ) {
            return 50 + $value;
        }

        // Must follow suit to count
        if ( $follows_suit ) {
            return $value;
        }

        return 0;
    }

    public function advance_turn( array $state ): array {
        if ( $state['phase'] === 'playing' ) {
            if ( empty( $state['trick'] ) ) {
                $state['current_turn'] = $state['trick_leader'];
            } else {
                // Skip partner if going alone
                $next = ( $state['current_turn'] + 1 ) % 4;
                if ( $state['going_alone'] ) {
                    $caller_partner = ( $state['caller'] + 2 ) % 4;
                    if ( $next === $caller_partner ) {
                        $next = ( $next + 1 ) % 4;
                    }
                }
                $state['current_turn'] = $next;
            }
        } elseif ( in_array( $state['phase'], [ 'calling_round1', 'calling_round2' ], true ) ) {
            $state['current_turn'] = ( $state['current_turn'] + 1 ) % 4;
        }

        return $state;
    }

    public function check_end_condition( array $state ): array {
        if ( $state['phase'] !== 'round_end' ) {
            return [ 'ended' => false, 'reason' => null, 'winners' => null ];
        }

        foreach ( $state['team_scores'] as $team => $score ) {
            if ( $score >= self::WIN_SCORE ) {
                return [
                    'ended'   => true,
                    'reason'  => 'win_score',
                    'winners' => $state['teams'][ $team ],
                ];
            }
        }

        return [ 'ended' => false, 'reason' => null, 'winners' => null ];
    }

    public function score_round( array $state ): array {
        $caller_team = $state['caller'] % 2;
        $defending_team = 1 - $caller_team;

        $caller_tricks = $state['tricks_won'][ $state['teams'][ $caller_team ][0] ] +
                         $state['tricks_won'][ $state['teams'][ $caller_team ][1] ];

        if ( $caller_tricks >= 3 ) {
            if ( $caller_tricks === 5 ) {
                // March
                $points = $state['going_alone'] ? 4 : 2;
            } else {
                $points = 1;
            }
            $state['team_scores'][ $caller_team ] += $points;
        } else {
            // Euchred
            $state['team_scores'][ $defending_team ] += 2;
        }

        return $state;
    }

    public function ai_move( array $state, int $player_seat, string $difficulty = 'beginner' ): array {
        $phase = $state['phase'];

        if ( $phase === 'calling_round1' ) {
            $hand = $state['hands'][ $player_seat ];
            $turned_suit = $state['turned_card']['suit'];
            $trump_count = count( array_filter( $hand, fn( $c ) => $c['suit'] === $turned_suit || 
                ( $c['rank'] === 'J' && $c['suit'] === $this->get_same_color_suit( $turned_suit ) ) ) );

            if ( $trump_count >= 3 || ( $player_seat === $state['dealer'] && $trump_count >= 2 ) ) {
                return [ 'action' => 'order_up' ];
            }
            return [ 'action' => 'pass' ];
        }

        if ( $phase === 'calling_round2' ) {
            $hand = $state['hands'][ $player_seat ];
            $turned_suit = $state['turned_card']['suit'];
            
            $best_suit = null;
            $best_count = 0;
            
            foreach ( [ 'hearts', 'diamonds', 'clubs', 'spades' ] as $suit ) {
                if ( $suit === $turned_suit ) continue;
                
                $count = count( array_filter( $hand, fn( $c ) => $c['suit'] === $suit ||
                    ( $c['rank'] === 'J' && $c['suit'] === $this->get_same_color_suit( $suit ) ) ) );
                
                if ( $count > $best_count ) {
                    $best_count = $count;
                    $best_suit = $suit;
                }
            }

            if ( $best_count >= 2 || $player_seat === $state['dealer'] ) {
                return [ 'action' => 'call', 'suit' => $best_suit ?? 'hearts' ];
            }
            return [ 'action' => 'pass' ];
        }

        if ( $phase === 'dealer_discard' ) {
            $hand = $state['hands'][ $player_seat ];
            $trump = $state['trump'];
            
            // Discard lowest non-trump
            $non_trump = array_filter( $hand, fn( $c ) => $this->get_effective_suit( $c, $trump ) !== $trump );
            
            if ( ! empty( $non_trump ) ) {
                usort( $non_trump, fn( $a, $b ) => $this->get_trick_value( $a, $trump, $trump ) - $this->get_trick_value( $b, $trump, $trump ) );
                return [ 'card_id' => $non_trump[0]['id'] ];
            }
            
            return [ 'card_id' => $hand[0]['id'] ];
        }

        if ( $phase === 'playing' ) {
            $valid = $this->get_valid_moves( $state, $player_seat );
            if ( empty( $valid ) ) return [];

            $ai_engine = new CGA_AI_Engine();
            $move = $ai_engine->pick_random_move( $valid );
            return [ 'card_id' => $move['card_id'] ];
        }

        return [];
    }

    public function get_valid_moves( array $state, int $player_seat ): array {
        if ( $state['current_turn'] !== $player_seat ) return [];

        $phase = $state['phase'];

        if ( $phase === 'calling_round1' ) {
            return [
                [ 'action' => 'pass' ],
                [ 'action' => 'order_up' ],
                [ 'action' => 'order_up_alone' ],
            ];
        }

        if ( $phase === 'calling_round2' ) {
            $moves = [];
            if ( $player_seat !== $state['dealer'] ) {
                $moves[] = [ 'action' => 'pass' ];
            }
            foreach ( [ 'hearts', 'diamonds', 'clubs', 'spades' ] as $suit ) {
                if ( $suit !== $state['turned_card']['suit'] ) {
                    $moves[] = [ 'action' => 'call', 'suit' => $suit ];
                }
            }
            return $moves;
        }

        if ( $phase === 'dealer_discard' || $phase === 'playing' ) {
            $hand = $state['hands'][ $player_seat ];
            $valid = [];

            foreach ( $hand as $card ) {
                $move = [ 'card_id' => $card['id'] ];
                if ( $this->validate_move( $state, $player_seat, $move ) === true ) {
                    $valid[] = $move;
                }
            }

            return $valid;
        }

        return [];
    }

    public function get_public_state( array $state, int $player_seat ): array {
        $public = $state;
        $public['hands'] = [];

        foreach ( $state['hands'] as $seat => $hand ) {
            $public['hands'][ $seat ] = $seat === $player_seat ? $hand : count( $hand );
        }

        $public['kitty'] = count( $state['kitty'] ?? [] );

        return $public;
    }

    private function find_card( array $hand, string $card_id ): ?array {
        foreach ( $hand as $card ) {
            if ( $card['id'] === $card_id ) return $card;
        }
        return null;
    }

    protected function get_card_value( array $card ): int {
        $values = [ '9' => 9, '10' => 10, 'J' => 11, 'Q' => 12, 'K' => 13, 'A' => 14 ];
        return $values[ $card['rank'] ] ?? 0;
    }
}
