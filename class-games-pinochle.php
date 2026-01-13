<?php
/**
 * Pinochle Game Module
 * 
 * 4-player Partnership Pinochle
 * - 48-card double deck (9, 10, J, Q, K, A x2)
 * - Bidding, melding, trick-taking
 * - First to 150 wins
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CGA_Game_Pinochle extends CGA_Game_Contract {

    protected $id = 'pinochle';
    protected $name = 'Pinochle';
    protected $type = 'card';
    protected $min_players = 4;
    protected $max_players = 4;
    protected $has_teams = true;
    protected $ai_supported = true;

    const WIN_SCORE = 150;
    const MIN_BID = 20;

    public function register_game(): array {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'type'         => $this->type,
            'min_players'  => $this->min_players,
            'max_players'  => $this->max_players,
            'has_teams'    => $this->has_teams,
            'ai_supported' => $this->ai_supported,
            'description'  => 'Partnership trick-taking with melds. Bid, meld, and take tricks!',
        ];
    }

    public function init_state( array $players, array $settings = [] ): array {
        return [
            'phase'        => 'bidding',
            'current_turn' => 0,
            'dealer'       => 0,
            'players'      => $this->format_players( $players ),
            'teams'        => [ [ 0, 2 ], [ 1, 3 ] ],
            'hands'        => [],
            'bids'         => [ null, null, null, null ],
            'high_bid'     => self::MIN_BID - 1,
            'high_bidder'  => null,
            'passed'       => [ false, false, false, false ],
            'trump'        => null,
            'melds'        => [ 0, 0, 0, 0 ],
            'trick'        => [],
            'trick_leader' => 1,
            'tricks_won'   => [ 0, 0, 0, 0 ],
            'counters'     => [ 0, 0 ],
            'team_scores'  => [ 0, 0 ],
            'round_number' => 1,
            'game_over'    => false,
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
        $deck = $this->create_pinochle_deck();
        $deck = $this->shuffle_deck( $deck );

        $deal = $this->deal_cards( $deck, 4, 12 );
        foreach ( $deal['hands'] as $seat => $hand ) {
            $state['hands'][ $seat ] = $this->sort_hand( $hand );
        }

        $state['phase'] = 'bidding';
        $state['bids'] = [ null, null, null, null ];
        $state['high_bid'] = self::MIN_BID - 1;
        $state['high_bidder'] = null;
        $state['passed'] = [ false, false, false, false ];
        $state['trump'] = null;
        $state['melds'] = [ 0, 0, 0, 0 ];
        $state['trick'] = [];
        $state['tricks_won'] = [ 0, 0, 0, 0 ];
        $state['counters'] = [ 0, 0 ];
        $state['current_turn'] = ( $state['dealer'] + 1 ) % 4;

        return $state;
    }

    private function create_pinochle_deck(): array {
        $suits = [ 'hearts', 'diamonds', 'clubs', 'spades' ];
        $ranks = [ '9', '10', 'J', 'Q', 'K', 'A' ];
        $deck = [];

        // Double deck
        for ( $copy = 0; $copy < 2; $copy++ ) {
            foreach ( $suits as $suit ) {
                foreach ( $ranks as $rank ) {
                    $deck[] = [
                        'suit' => $suit,
                        'rank' => $rank,
                        'id'   => "{$rank}_{$suit}_{$copy}",
                    ];
                }
            }
        }

        return $deck;
    }

    private function sort_hand( array $hand ): array {
        $suit_order = [ 'spades' => 0, 'hearts' => 1, 'diamonds' => 2, 'clubs' => 3 ];
        $rank_order = [ 'A' => 6, '10' => 5, 'K' => 4, 'Q' => 3, 'J' => 2, '9' => 1 ];

        usort( $hand, function( $a, $b ) use ( $suit_order, $rank_order ) {
            $suit_diff = $suit_order[ $a['suit'] ] - $suit_order[ $b['suit'] ];
            if ( $suit_diff !== 0 ) return $suit_diff;
            return $rank_order[ $b['rank'] ] - $rank_order[ $a['rank'] ];
        });

        return $hand;
    }

    public function validate_move( array $state, int $player_seat, array $move ) {
        if ( $state['current_turn'] !== $player_seat ) {
            return new WP_Error( 'not_your_turn', 'Not your turn.' );
        }

        if ( $state['phase'] === 'bidding' ) {
            $action = $move['action'] ?? null;
            if ( $action === 'pass' ) return true;
            if ( $action === 'bid' ) {
                $bid = $move['bid'] ?? 0;
                if ( $bid <= $state['high_bid'] ) {
                    return new WP_Error( 'bid_too_low', 'Bid must be higher than current bid.' );
                }
                return true;
            }
            return new WP_Error( 'invalid_action', 'Must bid or pass.' );
        }

        if ( $state['phase'] === 'trump_selection' ) {
            $suit = $move['suit'] ?? null;
            if ( ! in_array( $suit, [ 'hearts', 'diamonds', 'clubs', 'spades' ], true ) ) {
                return new WP_Error( 'invalid_suit', 'Must select a valid suit.' );
            }
            return true;
        }

        if ( $state['phase'] === 'playing' ) {
            $card_id = $move['card_id'] ?? null;
            if ( ! $card_id ) return new WP_Error( 'no_card', 'No card specified.' );

            $hand = $state['hands'][ $player_seat ];
            $card = $this->find_card( $hand, $card_id );
            if ( ! $card ) return new WP_Error( 'invalid_card', 'Card not in hand.' );

            if ( ! empty( $state['trick'] ) ) {
                $lead = $state['trick'][0]['card'];
                $lead_suit = $lead['suit'];
                $trump = $state['trump'];

                // Must follow suit
                if ( $this->has_suit( $hand, $lead_suit ) && $card['suit'] !== $lead_suit ) {
                    return new WP_Error( 'must_follow', 'Must follow suit.' );
                }

                // If can't follow, must trump if possible
                if ( ! $this->has_suit( $hand, $lead_suit ) && $lead_suit !== $trump ) {
                    if ( $this->has_suit( $hand, $trump ) && $card['suit'] !== $trump ) {
                        return new WP_Error( 'must_trump', 'Must play trump if unable to follow.' );
                    }
                }
            }

            return true;
        }

        return new WP_Error( 'invalid_phase', 'Cannot make moves now.' );
    }

    public function apply_move( array $state, int $player_seat, array $move ): array {
        if ( $state['phase'] === 'bidding' ) {
            if ( $move['action'] === 'pass' ) {
                $state['passed'][ $player_seat ] = true;
            } else {
                $state['high_bid'] = $move['bid'];
                $state['high_bidder'] = $player_seat;
            }

            // Check if bidding is complete
            $active_bidders = array_filter( $state['passed'], fn( $p ) => ! $p );
            if ( count( $active_bidders ) === 1 || ( $state['high_bidder'] !== null && count( $active_bidders ) === 0 ) ) {
                if ( $state['high_bidder'] === null ) {
                    // Everyone passed - dealer must take minimum
                    $state['high_bidder'] = $state['dealer'];
                    $state['high_bid'] = self::MIN_BID;
                }
                $state['phase'] = 'trump_selection';
                $state['current_turn'] = $state['high_bidder'];
            }

            return $state;
        }

        if ( $state['phase'] === 'trump_selection' ) {
            $state['trump'] = $move['suit'];
            $state = $this->calculate_melds( $state );
            $state['phase'] = 'playing';
            $state['trick_leader'] = $state['high_bidder'];
            $state['current_turn'] = $state['high_bidder'];
            return $state;
        }

        if ( $state['phase'] === 'playing' ) {
            $card_id = $move['card_id'];
            $hand = $state['hands'][ $player_seat ];
            $card = $this->find_card( $hand, $card_id );

            $state['hands'][ $player_seat ] = array_values( array_filter( $hand, fn( $c ) => $c['id'] !== $card_id ) );
            $state['trick'][] = [ 'seat' => $player_seat, 'card' => $card ];

            if ( count( $state['trick'] ) === 4 ) {
                $state = $this->resolve_trick( $state );
            }
        }

        return $state;
    }

    private function calculate_melds( array $state ): array {
        $trump = $state['trump'];
        
        for ( $seat = 0; $seat < 4; $seat++ ) {
            $hand = $state['hands'][ $seat ];
            $meld = 0;

            // Pinochle (J diamonds + Q spades)
            $jd = count( array_filter( $hand, fn( $c ) => $c['rank'] === 'J' && $c['suit'] === 'diamonds' ) );
            $qs = count( array_filter( $hand, fn( $c ) => $c['rank'] === 'Q' && $c['suit'] === 'spades' ) );
            $pinochles = min( $jd, $qs );
            if ( $pinochles === 2 ) $meld += 30;
            elseif ( $pinochles === 1 ) $meld += 4;

            // Marriages (K + Q same suit)
            foreach ( [ 'hearts', 'diamonds', 'clubs', 'spades' ] as $suit ) {
                $kings = count( array_filter( $hand, fn( $c ) => $c['rank'] === 'K' && $c['suit'] === $suit ) );
                $queens = count( array_filter( $hand, fn( $c ) => $c['rank'] === 'Q' && $c['suit'] === $suit ) );
                $marriages = min( $kings, $queens );
                
                if ( $suit === $trump ) {
                    $meld += $marriages * 4; // Royal marriage
                } else {
                    $meld += $marriages * 2;
                }
            }

            // Nines of trump
            $trump_nines = count( array_filter( $hand, fn( $c ) => $c['rank'] === '9' && $c['suit'] === $trump ) );
            $meld += $trump_nines;

            // Runs (A-10-K-Q-J of trump)
            $run_cards = [ 'A', '10', 'K', 'Q', 'J' ];
            $has_run = true;
            foreach ( $run_cards as $rank ) {
                if ( ! $this->has_card( $hand, $trump, $rank ) ) {
                    $has_run = false;
                    break;
                }
            }
            if ( $has_run ) $meld += 15;

            // Aces around (4 aces, different suits)
            $aces = [];
            foreach ( $hand as $card ) {
                if ( $card['rank'] === 'A' ) $aces[ $card['suit'] ] = true;
            }
            if ( count( $aces ) === 4 ) $meld += 10;

            $state['melds'][ $seat ] = $meld;
        }

        return $state;
    }

    private function resolve_trick( array $state ): array {
        $trump = $state['trump'];
        $lead = $state['trick'][0]['card'];
        $lead_suit = $lead['suit'];
        
        $winner_seat = $state['trick'][0]['seat'];
        $winner_value = $this->get_trick_value( $lead, $trump, $lead_suit );

        foreach ( $state['trick'] as $play ) {
            $value = $this->get_trick_value( $play['card'], $trump, $lead_suit );
            if ( $value > $winner_value ) {
                $winner_value = $value;
                $winner_seat = $play['seat'];
            }
        }

        $state['tricks_won'][ $winner_seat ]++;

        // Count counters (A, 10, K = 1 point each)
        $counter_ranks = [ 'A', '10', 'K' ];
        foreach ( $state['trick'] as $play ) {
            if ( in_array( $play['card']['rank'], $counter_ranks, true ) ) {
                $team = $winner_seat % 2;
                $state['counters'][ $team ]++;
            }
        }

        $state['trick'] = [];
        $state['trick_leader'] = $winner_seat;

        if ( $this->is_round_over( $state ) ) {
            // Last trick bonus
            $state['counters'][ $winner_seat % 2 ]++;
            $state['phase'] = 'round_end';
            $state = $this->score_round( $state );
        }

        return $state;
    }

    private function get_trick_value( array $card, string $trump, string $lead_suit ): int {
        $rank_order = [ 'A' => 6, '10' => 5, 'K' => 4, 'Q' => 3, 'J' => 2, '9' => 1 ];
        $base = $rank_order[ $card['rank'] ] ?? 0;

        if ( $card['suit'] === $trump ) {
            return 100 + $base;
        }

        if ( $card['suit'] === $lead_suit ) {
            return $base;
        }

        return 0;
    }

    private function is_round_over( array $state ): bool {
        foreach ( $state['hands'] as $hand ) {
            if ( ! empty( $hand ) ) return false;
        }
        return true;
    }

    public function advance_turn( array $state ): array {
        if ( $state['phase'] === 'bidding' ) {
            do {
                $state['current_turn'] = ( $state['current_turn'] + 1 ) % 4;
            } while ( $state['passed'][ $state['current_turn'] ] && 
                      count( array_filter( $state['passed'], fn( $p ) => ! $p ) ) > 0 );
        } elseif ( $state['phase'] === 'playing' ) {
            if ( empty( $state['trick'] ) ) {
                $state['current_turn'] = $state['trick_leader'];
            } else {
                $state['current_turn'] = ( $state['current_turn'] + 1 ) % 4;
            }
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
        $bidding_team = $state['high_bidder'] % 2;
        $other_team = 1 - $bidding_team;

        // Calculate team totals
        $team_melds = [ 0, 0 ];
        for ( $seat = 0; $seat < 4; $seat++ ) {
            $team_melds[ $seat % 2 ] += $state['melds'][ $seat ];
        }

        $bidding_total = $team_melds[ $bidding_team ] + $state['counters'][ $bidding_team ];
        $other_total = $team_melds[ $other_team ] + $state['counters'][ $other_team ];

        // Did bidding team make their bid?
        if ( $bidding_total >= $state['high_bid'] ) {
            $state['team_scores'][ $bidding_team ] += $bidding_total;
        } else {
            // Set - lose bid amount
            $state['team_scores'][ $bidding_team ] -= $state['high_bid'];
        }

        // Other team always scores if they took any counters
        if ( $state['counters'][ $other_team ] > 0 ) {
            $state['team_scores'][ $other_team ] += $other_total;
        }

        return $state;
    }

    public function ai_move( array $state, int $player_seat, string $difficulty = 'beginner' ): array {
        if ( $state['phase'] === 'bidding' ) {
            $hand = $state['hands'][ $player_seat ];
            $estimated_meld = $this->estimate_meld( $hand );
            $estimated_tricks = count( array_filter( $hand, fn( $c ) => in_array( $c['rank'], [ 'A', '10', 'K' ], true ) ) ) / 3;
            $estimated = $estimated_meld + ( $estimated_tricks * 2 );

            if ( $estimated > $state['high_bid'] + 2 ) {
                return [ 'action' => 'bid', 'bid' => $state['high_bid'] + 1 ];
            }
            return [ 'action' => 'pass' ];
        }

        if ( $state['phase'] === 'trump_selection' ) {
            $hand = $state['hands'][ $player_seat ];
            $best_suit = 'spades';
            $best_count = 0;

            foreach ( [ 'hearts', 'diamonds', 'clubs', 'spades' ] as $suit ) {
                $count = count( array_filter( $hand, fn( $c ) => $c['suit'] === $suit ) );
                if ( $count > $best_count ) {
                    $best_count = $count;
                    $best_suit = $suit;
                }
            }

            return [ 'suit' => $best_suit ];
        }

        if ( $state['phase'] === 'playing' ) {
            $valid = $this->get_valid_moves( $state, $player_seat );
            if ( empty( $valid ) ) return [];

            $ai_engine = new CGA_AI_Engine();
            $move = $ai_engine->pick_random_move( $valid );
            return [ 'card_id' => $move['card_id'] ];
        }

        return [];
    }

    private function estimate_meld( array $hand ): int {
        $meld = 0;
        $jd = count( array_filter( $hand, fn( $c ) => $c['rank'] === 'J' && $c['suit'] === 'diamonds' ) );
        $qs = count( array_filter( $hand, fn( $c ) => $c['rank'] === 'Q' && $c['suit'] === 'spades' ) );
        $meld += min( $jd, $qs ) * 4;

        foreach ( [ 'hearts', 'diamonds', 'clubs', 'spades' ] as $suit ) {
            $kings = count( array_filter( $hand, fn( $c ) => $c['rank'] === 'K' && $c['suit'] === $suit ) );
            $queens = count( array_filter( $hand, fn( $c ) => $c['rank'] === 'Q' && $c['suit'] === $suit ) );
            $meld += min( $kings, $queens ) * 2;
        }

        return $meld;
    }

    public function get_valid_moves( array $state, int $player_seat ): array {
        if ( $state['current_turn'] !== $player_seat ) return [];

        if ( $state['phase'] === 'bidding' ) {
            $moves = [ [ 'action' => 'pass' ] ];
            for ( $bid = $state['high_bid'] + 1; $bid <= 50; $bid++ ) {
                $moves[] = [ 'action' => 'bid', 'bid' => $bid ];
            }
            return $moves;
        }

        if ( $state['phase'] === 'trump_selection' ) {
            return [
                [ 'suit' => 'hearts' ],
                [ 'suit' => 'diamonds' ],
                [ 'suit' => 'clubs' ],
                [ 'suit' => 'spades' ],
            ];
        }

        if ( $state['phase'] === 'playing' ) {
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
        return $public;
    }

    private function find_card( array $hand, string $card_id ): ?array {
        foreach ( $hand as $card ) {
            if ( $card['id'] === $card_id ) return $card;
        }
        return null;
    }

    private function has_suit( array $hand, string $suit ): bool {
        foreach ( $hand as $card ) {
            if ( $card['suit'] === $suit ) return true;
        }
        return false;
    }

    private function has_card( array $hand, string $suit, string $rank ): bool {
        foreach ( $hand as $card ) {
            if ( $card['suit'] === $suit && $card['rank'] === $rank ) return true;
        }
        return false;
    }

    protected function get_card_value( array $card ): int {
        $values = [ '9' => 0, 'J' => 2, 'Q' => 3, 'K' => 4, '10' => 10, 'A' => 11 ];
        return $values[ $card['rank'] ] ?? 0;
    }
}
