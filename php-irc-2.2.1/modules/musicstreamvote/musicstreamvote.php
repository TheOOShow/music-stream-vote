<?php
define( 'MUSICSTREAMVOTE_DEBUG', TRUE );

class musicstreamvote extends module {

    public $title = 'Music Stream Vote';
    public $author = 'Brendan Kidwell';
    public $version = '1.0';

    private $mod_dir = '';
    private $options = array();
    private $in_channels = array();
    private $timers = array();
    private $curl = array();
    private $now_playing = '';
    private $now_playing_response = '';
    private $pending_votes = array();
    private $last_error = '';

    public function init() {
        $this->dbg( 'init()' );

        $this->mod_dir = dirname( __FILE__ ) . '/modules/musicstreamvote/';
        foreach ( explode( ',', 'bootstrap.conf.php,musicstreamvote.conf,options.json.php' ) as $f ) {
            if ( ! file_exists( $this->mod_dir . $f ) ) {
                die( 'Fatal error: ' . $this->mod_dir . $f . "is missing.\n" );
            }
        }

        if ( file_exists( $this->mod_dir . 'restart' ) ) {
            unlink( $this->mod_dir . 'restart' );
        }

        $this->options = json_decode( file_get_contents( $this->mod_dir . 'options.json.php' ), TRUE );
        $this->curl['wordpress'] = FALSE;
        $this->curl['streaminfo'] = FALSE;
    }

    public function destroy() {
        $this>dbg( 'destroy()' );
        foreach ( $this->timers as $key => $value ) {
            $this->timerClass->removeTimer( $key );
        }
        foreach ( $this->curl as $key => $value ) {
            curl_close( $value );
        }
    }

    private function evt_logged_in() {
        if ( count($this->in_channels) > 1 ) { return; }

        $this->dbg( 'checking in' );
        $this->webservice( 'checkin', array() );
        $this->dbg( 'done checking in ' );

        $this->add_timer( 'evt_stream_poll', $this->options['stream_status_poll_interval_sec'] );
        $this->add_timer( 'evt_restart_poll', $this->options['restart_poll_interval_sec'] );
        $this->evt_stream_poll();
    }

    public function evt_stream_poll() {
        $this->dbg( 'entering evt_stream_poll()' );
        $data = $this->streaminfo( $this->options['stream_status_url'] );
        $this->dbg( 'current stream_title: ' . $data['stream_title'] );

        if ( $data['stream_title'] != $this->now_playing ) {
            $this->now_playing = $data['stream_title'];
            $this->now_playing_response = '';
        }

        // Try to post 'now playing' to web service on each polling cycle until success
        if ( $this->now_playing_response == '' ) {
            $response = $this->webservice( 'track_start', array(
                'time_utc' => date('Y-m-d H:i:s', time() ),
                'stream_title' => $data['stream_title']
            ));
            if ( $response['output'] ) {
                $this->now_playing_response = $response['output'];
                $this->announce( $response['output'] );
            }
        }

        $this->dbg( 'exiting evt_stream_poll()' );
        return TRUE;
    }

    public function evt_restart_poll() {
        if ( file_exists( $this->mod_dir . 'restart' ) ) {
            $this->announce ( 'is being restarted.', TRUE );
            //exit();
            $this->add_timer( 'evt_abort', 2 );
        }
        return TRUE;
    }

    public function evt_abort() {
        die();
    }

    public function evt_raw( $line, $args ) {
        $cmd = $line['cmd'];
        if ( $cmd == 307 || $cmd == 318 ) {
            $this->evt_whois( $line, $args );
        }
        if ( $cmd == 'PRIVMSG' ) {
            if (
                preg_match(
                    "/[\\W]*" . $this->options['irc_nick'] . "\\W.*/i", $line['text']
                ) ||
                (
                    $line['to'] == $this->options['irc_nick'] &&
                    substr( $line['text'], 0, 1 ) != '!'
                )
            ) {
                $this->evt_sayhi( $line, $args );
            }
        }
    }

    private function evt_whois( $line, $args ) {
        $cmd = $line['cmd'];
        $subject = $line['params'];
        if ( array_key_exists( $subject, $this->pending_votes ) ) {
            if ( $cmd == 307 ) {
                $this->pending_votes[$subject]['is_authed'] = 1;
            } elseif ( $cmd == 318 ) {
                $this->cmd_vote_finish( $subject );
            }
        }
    }

    private function evt_sayhi( $line, $args ) {
        print_r($line);
        $response = $this->webservice( 'sayhi', array(
            'nick' => $line['fromNick'],
        ), $line );
        if ( $response['output'] ) {
            $this->reply( $line, $response['output'] );
        }
    }

    public function cmd_help( $line, $args ) {
        $response = $this->webservice( 'help', array(), $line );
        if ( $response['output'] ) {
            $this->reply( $line, $response['output'] );
        }
    }

    public function cmd_nowplaying( $line, $args ) {
        if ( $this->now_playing_response ) {
            $this->reply( $line, $this->now_playing_response );
        }
    }

    public function cmd_vote( $line, $args ) {
        $fromNick = $line['fromNick'];
        $vote = array(
            'line' => $line,
            'time_utc' => date('Y-m-d H:i:s', time() ),
            'stream_title' => $this->now_playing,
            'value' => $args['query'],
            'nick' => $fromNick,
            'user_id' => $line['from'],
            'is_authed' => 0
        );
        $this->pending_votes[$fromNick] = $vote;
        $this->ircClass->sendRaw( "WHOIS $fromNick" );
    }

    public function cmd_vote_finish( $subject ) {
        $vote = $this->pending_votes[$subject];
        $line = $vote['line'];
        $this->dbg("Continuing vote for $subject.");
        $response = $this->webservice( 'post_vote', array(
            'time_utc' => $vote['time_utc'],
            'stream_title' => $vote['stream_title'],
            'value' => $vote['value'],
            'nick' => $vote['nick'],
            'user_id' => $vote['user_id'],
            'is_authed' => $vote['is_authed'],
        ), $line );
        if ( $response['output'] ) {
            $this->reply( $line, $response['output'] );
        }
        unset($this->pending_votes[$subject]);
    }

    public function cmd_unvote( $line, $args ) {
        $response = $this->webservice( 'undo_vote', array(
            'nick' => $line['fromNick'],
        ), $line );
        print_r($response);
        if ( $response['output'] ) {
            $this->reply( $line, $response['output'] );
        }
    }

    public function cmd_like( $line, $args ) {
        $args['query'] = '+3';
        $this->cmd_vote( $line, $args );
    }

    public function cmd_hate( $line, $args ) {
        $args['query'] = '-3';
        $this->cmd_vote( $line, $args );
    }

    public function cmd_stats( $line, $args ) {
        $response = $this->webservice( 'stats', array(), $line );
        if ( $response['output'] ) {
            $this->reply( $line, $response['output'] );
        }
    }

    public function evt_join( $line ) {
        if ( $this->options['irc_nick'] == $line['fromNick'] ) {
            $this->dbg( 'evt_join(): ' . $line['text'] );
            $this->in_channels[$line['text']] = 1;
            $this->evt_logged_in();
        }
    }

    private function reply( $line, $text ) {
        if ( $line['to'] == $this->options['irc_nick'] ) {
            $to = $line['fromNick'];
        } else {
            $to = $line['to'];
        }
        $text = str_ireplace(
            array('<b>', '</b>'),
            array("\02", "\017"),
            $text
        );
        foreach ( explode( "\n", $text ) as $output_line ) {
            $this->ircClass->privMsg($to, $output_line, $queue = 1);
        }
    }

    private function announce( $text, $action = FALSE ) {
        $text = str_ireplace(
            array('<b>', '</b>'),
            array("\02", "\017"),
            $text
        );
        foreach ( explode( "\n", $text ) as $output_line ) {
            foreach ( $this->in_channels as $key => $value ) {
                if ( $action ) {
                    $this->ircClass->action(
                        $key, $output_line, $queue = 1
                    );
                } else {
                    $this->ircClass->privMsg(
                        $key, $output_line, $queue = 1
                    );
                }
            }
        }
    }

    private function dbg( $text ) {
        $this->ircClass->log( "[MSV] $text" );
    }

    private function webservice( $method, $args, $line = NULL ) {
        $args['web_service_password'] = $this->options['web_service_password'];

        $fields = array(
            'musicstreamvote_botcall' => '1',
            'method' => $method,
            'args' => json_encode( $args )
        );
        $data = http_build_query( $fields );

        if ( $this->curl['wordpress'] === FALSE ) {
            $this->curl['wordpress'] = curl_init();
        }
        $ch = &$this->curl['wordpress'];

        curl_setopt( $ch, CURLOPT_URL, $this->options['web_service_url'] );
        curl_setopt( $ch, CURLOPT_POST, count( $fields ) );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
            'Connection: Keep-Alive',
            'Keep-Alive: 300'
        ) );
        ob_start();
        $result = curl_exec( $ch );
        $error = curl_error( $ch );
        $data = json_decode( ob_get_contents(), TRUE );
        ob_end_clean();

        if ( $result === FALSE ) {
            $data = array();
            $data['status'] = 'error';
            $data['error_message'] = $error;
        }

        if ( $data['status'] == 'error' ) {
            if ( $line ) {
                $this->reply( $line, "\02Error:\017 " . $data['error_message'] );
            } else {
                if ( $data['error_message'] != $this->last_error ) {
                    $this->announce( "\02Error:\017 " . $data['error_message'] );
                    $this->last_error = $data['error_message'];
                }
            }
        }
        // print_r($data);

        return $data;
    }

    private function streaminfo( $url ) {
        if ( $this->curl['streaminfo'] === FALSE ) {
            $this->curl['streaminfo'] = curl_init();
        }
        $ch = &$this->curl['streaminfo'];

        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
            'Connection: Keep-Alive',
            'Keep-Alive: 300'
        ) );
        ob_start();
        $result = curl_exec( $ch );
        $error = curl_error( $ch );
        $data = array();
        $data['xml'] = ob_get_contents();
        $data['stream_title'] = '';
        ob_end_clean();

        if ( $result === FALSE ) {
            $data = array();
            $data['status'] = 'error';
            $data['error_message'] = $error;
            $data['xml'] = '';
        } else {
            $data['status'] = 'ok';
            $data['error_message'] = '';
            $info = new SimpleXMLElement($data['xml']);
            $data['stream_title'] = (string) $info->trackList[0]->track[0]->title[0];
        }

        if ( $data['status'] == 'error' ) {
            foreach ( $this->in_channels as $key => $value ) {
                $this->ircClass->privMsg(
                    $key, "\02Error:\017 " . $data['error_message'], $queue = 1
                );
            }
        }
  
        return $data;
    }

    private function add_timer( $function_name, $interval_sec ) {
        $timer_name = 'msv_' . $function_name;
        $this->dbg( "add_timer($timer_name, $function_name, $interval_sec)" );

        $this->timerClass->addTimer(
            $timer_name, $this, $function_name, '', $interval_sec, false
        );
        $this->timers[$timer_name] = 1;
    }
}

?>