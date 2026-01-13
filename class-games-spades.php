<?php
/**
 * Spades Game Module
 * 
 * Partnership Spades (4 players, 2 teams)
 * - Spades always trump
 * - Bidding phase
 * - First to 500 wins, -200 loses
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CGA_Game_Spades extends CGA_Game_Contract {

    protected $id = 'spades';
    protected $name = 'Spades';
    protected $type = 'card';
    protected $min_players = 4;
    protected $max_players = 4;
    protected $has_teams = true;
    protected $ai_supported = true;

    const WIN_SCORE = 500;
    const LOSE_SCORE = -200;

    public function register_game(): array {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'type'         => $this->type,
            'min_players'  => $this->min_players,
            'max_players'  => $this->max_players,
            'has_teams'    => $this->has_teams,
            'ai_supported' => $this->ai_supported,
            'description'  => 'Partnership trick-taking with spades as trump. Bid and make your tricks!',
        ];
    }

    public function init_state( array $players, array $settings = [] ): array {
        return [
            'phase'          => 'bidding',
            'current_turn'   => 0,
            'dealer'         => 0,
            'players'        => $this->format_players( $players ),
            'teams'          => [ [ 0, 2 ], [ 1, 3 ] ],
            'hands'          => [],
            'bids'           => [ null, null, null, null ],
            'tricks_won'     => [ 0, 0, 0, 0 ],
            'trick'          => [],
            'trick_leader'   => 1,
            'spades_broken'  => false,
            'team_scores'    => [ 0, 0 ],
            'team_bags'      => [ 0, 0 ],
            'round_number'   => 1,
            'game_over'      => false,
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
        $deck = $this->create_standard_deck();
        $deck = $this->shuffle_deck( $deck );
        $deal = $this->deal_cards( $deck, 4, 13 );

        foreach ( $deal['hands'] as $seat => $hand ) {
            $state['hands'][ $seat ] = $this->sort_hand( $hand );
        }

        $state['phase'] = 'bidding';
        $state['bids'] = [ null, null, null, null ];
        $state['tricks_won'] = [ 0, 0, 0, 0 ];
        $state['trick'] = [];
        $state['spades_broken'] = false;
        $state['current_turn'] = ( $state['dealer'] + 1 ) % 4;
        $state['trick_leader'] = $state['current_turn'];

        return $state;
    }

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

    public function validate_move( array $state, int $player_seat, array $move ) {
        if ( $state['current_turn'] !== $player_seat ) {
            return new WP_Error( 'not_your_turn', 'It is not your turn.' );
        }

        if ( $state['phase'] === 'bidding' ) {
            $bid = $move['bid'] ?? null;
            if ( $bid === null ) {
                return new WP_Error( 'no_bid', 'No bid specified.' );
            }
            if ( $bid === 'nil' || ( is_numeric( $bid ) && $bid >= 1 && $bid <= 13 ) ) {
                return true;
            }
            return new WP_Error( 'invalid_bid', 'Bid must be nil or 1-13.' );
        }

        if ( $state['phase'] === 'playing' ) {
            $card_id = $move['card_id'] ?? null;
            if ( ! $card_id ) {
                return new WP_Error( 'no_card', 'No card specified.' );
            }

            $hand = $state['hands'][ $player_seat ];
            $card = $this->find_card( $hand, $card_id );

            if ( ! $card ) {
                return new WP_Error( 'invalid_card', 'You do not have that card.' );
            }

            if ( ! empty( $state['trick'] ) ) {
                $lead_suit = $state['trick'][0]['card']['suit'];
                if ( $this->has_suit( $hand, $lead_suit ) && $card['suit'] !== $lead_suit ) {
                    return new WP_Error( 'must_follow', 'You must follow suit.' );
                }
            }

            if ( empty( $state['trick'] ) && ! $state['spades_broken'] ) {
                if ( $card['suit'] === 'spades' && ! $this->only_has_spades( $hand ) ) {
                    return new WP_Error( 'spades_not_broken', 'Spades have not been broken.' );
                }
            }

            return true;
        }

        return new WP_Error( 'invalid_phase', 'Cannot make moves in this phase.' );
    }

    public function apply_move( array $state, int $player_seat, array $move ): array {
        if ( $state['phase'] === 'bidding' ) {
            $bid = $move['bid'];
            $state['bids'][ $player_seat ] = $bid === 'nil' ? 0 : (int) $bid;

            if ( ! in_array( null, $state['bids'], true ) ) {
                $state['phase'] = 'playing';
            }
            return $state;
        }

        if ( $state['phase'] === 'playing' ) {
            $card_id = $move['card_id'];
            $hand = $state['hands'][ $player_seat ];
            $card = $this->find_card( $hand, $card_id );

            $state['hands'][ $player_seat ] = array_values( array_filter( $hand, fn( $c ) => $c['id'] !== $card_id ) );
            $state['trick'][] = [ 'seat' => $player_seat, 'card' => $card ];

            if ( $card['suit'] === 'spades' && ! $state['spades_broken'] ) {
                $state['spades_broken'] = true;
            }

            if ( count( $state['trick'] ) === 4 ) {
                $state = $this->resolve_trick( $state );
            }
        }

        return $state;
    }

    private function resolve_trick( array $state ): array {
        $lead_suit = $state['trick'][0]['card']['suit'];
        $winner_seat = $state['trick'][0]['seat'];
        $winner_value = $this->get_card_value( $state['trick'][0]['card'] );
        $winner_is_trump = $lead_suit === 'spades';

        foreach ( $state['trick'] as $play ) {
            $is_trump = $play['card']['suit'] === 'spades';
            $value = $this->get_card_value( $play['card'] );

            if ( $is_trump && ! $winner_is_trump ) {
                $winner_seat = $play['seat'];
                $winner_value = $value;
                $winner_is_trump = true;
            } elseif ( $is_trump === $winner_is_trump ) {
                if ( $is_trump || $play['card']['suit'] === $lead_suit ) {
                    if ( $value > $winner_value ) {
                        $winner_seat = $play['seat'];
                        $winner_value = $value;
                    }
                }
            }
        }

        $state['tricks_won'][ $winner_seat ]++;
        $state['trick'] = [];
        $state['trick_leader'] = $winner_seat;

        if ( $this->is_round_over( $state ) ) {
            $state['phase'] = 'round_end';
            $state = $this->score_round( $state );
        }

        return $state;
    }

    private function is_round_over( array $state ): bool {
        foreach ( $state['hands'] as $hand ) {
            if ( ! empty( $hand ) ) return false;
        }
        return true;
    }

    public function advance_turn( array $state ): array {
        if ( $state['phase'] === 'bidding' ) {
            $state['current_turn'] = ( $state['current_turn'] + 1 ) % 4;
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
                return [ 'ended' => true, 'reason' => 'win_score', 'winners' => $state['teams'][ $team ] ];
            }
            if ( $score <= self::LOSE_SCORE ) {
                return [ 'ended' => true, 'reason' => 'lose_score', 'winners' => $state['teams'][ $team === 0 ? 1 : 0 ] ];
            }
        }

        return [ 'ended' => false, 'reason' => null, 'winners' => null ];
    }

    public function score_round( array $state ): array {
        foreach ( [ 0, 1 ] as $team ) {
            $seats = $state['teams'][ $team ];
            $team_bid = 0;
            $team_tricks = 0;
            $nil_results = [];

            foreach ( $seats as $seat ) {
                $bid = $state['bids'][ $seat ];
                $tricks = $state['tricks_won'][ $seat ];

                if ( $bid === 0 ) {
                    $nil_results[] = $tricks === 0 ? 100 : -100;
                } else {
                    $team_bid += $bid;
                    $team_tricks += $tricks;
                }
            }

            $round_score = 0;

            if ( $team_tricks >= $team_bid && $team_bid > 0 ) {
                $round_score = $team_bid * 10;
                $bags = $team_tricks - $team_bid;
                $round_score += $bags;
                $state['team_bags'][ $team ] += $bags;

                if ( $state['team_bags'][ $team ] >= 10 ) {
                    $round_score -= 100;
                    $state['team_bags'][ $team ] -= 10;
                }
            } elseif ( $team_bid > 0 ) {
                $round_score = -$team_bid * 10;
            }

            foreach ( $nil_results as $nil_score ) {
                $round_score += $nil_score;
            }

            $state['team_scores'][ $team ] += $round_score;
        }

        return $state;
    }

    public function ai_move( array $state, int $player_seat, string $difficulty = 'beginner' ): array {
        if ( $state['phase'] === 'bidding' ) {
            $hand = $state['hands'][ $player_seat ];
            $tricks = 0;
            $spades = array_filter( $hand, fn( $c ) => $c['suit'] === 'spades' );

            foreach ( $spades as $spade ) {
                $value = $this->get_card_value( $spade );
                if ( $value >= 12 ) $tricks++;
                elseif ( $value >= 10 && count( $spades ) >= 4 ) $tricks += 0.5;
            }

            foreach ( $hand as $card ) {
                if ( $card['suit'] !== 'spades' ) {
                    if ( $card['rank'] === 'A' ) $tricks++;
                    elseif ( $card['rank'] === 'K' ) $tricks += 0.5;
                }
            }

            $bid = max( 1, (int) round( $tricks ) );
            if ( $difficulty === 'beginner' ) {
                $bid = max( 1, min( 13, $bid + random_int( -1, 1 ) ) );
            }

            return [ 'bid' => $bid ];
        }

        $valid = $this->get_valid_moves( $state, $player_seat );
        if ( empty( $valid ) ) return [];

        $ai_engine = new CGA_AI_Engine();
        if ( $difficulty === 'beginner' && ! $ai_engine->should_play_optimal( $difficulty ) ) {
            $move = $ai_engine->pick_random_move( $valid );
            return [ 'card_id' => $move['card_id'] ];
        }

        $hand = $state['hands'][ $player_seat ];
        $trick = $state['trick'];

        if ( empty( $trick ) ) {
            // Lead
            $off_suit = array_filter( $hand, fn( $c ) => $c['suit'] !== 'spades' );
            if ( ! empty( $off_suit ) ) {
                usort( $off_suit, fn( $a, $b ) => $this->get_card_value( $a ) - $this->get_card_value( $b ) );
                return [ 'card_id' => $off_suit[0]['id'] ];
            }
            return [ 'card_id' => $hand[0]['id'] ];
        }

        // Follow
        $lead_suit = $trick[0]['card']['suit'];
        $suit_cards = array_filter( $hand, fn( $c ) => $c['suit'] === $lead_suit );

        if ( ! empty( $suit_cards ) ) {
            usort( $suit_cards, fn( $a, $b ) => $this->get_card_value( $a ) - $this->get_card_value( $b ) );
            return [ 'card_id' => $suit_cards[0]['id'] ];
        }

        // Can't follow
        $non_spades = array_filter( $hand, fn( $c ) => $c['suit'] !== 'spades' );
        if ( ! empty( $non_spades ) ) {
            usort( $non_spades, fn( $a, $b ) => $this->get_card_value( $a ) - $this->get_card_value( $b ) );
            return [ 'card_id' => $non_spades[0]['id'] ];
        }

        return [ 'card_id' => $hand[0]['id'] ];
    }

    public function get_valid_moves( array $state, int $player_seat ): array {
        if ( $state['phase'] === 'bidding' && $state['current_turn'] === $player_seat ) {
            $bids = [ [ 'bid' => 'nil' ] ];
            for ( $i = 1; $i <= 13; $i++ ) {
                $bids[] = [ 'bid' => $i ];
            }
            return $bids;
        }

        if ( $state['phase'] !== 'playing' || $state['current_turn'] !== $player_seat ) {
            return [];
        }

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

    private function only_has_spades( array $hand ): bool {
        foreach ( $hand as $card ) {
            if ( $card['suit'] !== 'spades' ) return false;
        }
        return true;
    }

    protected function get_card_value( array $card ): int {
        $values = [ '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7, '8' => 8, '9' => 9, '10' => 10, 'J' => 11, 'Q' => 12, 'K' => 13, 'A' => 14 ];
        return $values[ $card['rank'] ] ?? 0;
    }
}
