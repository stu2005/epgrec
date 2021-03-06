<?php
//include_once( INSTALL_PATH . '/config.php');
include_once( INSTALL_PATH . '/DBRecord.class.php' );
include_once( INSTALL_PATH . '/reclib.php' );
include_once( INSTALL_PATH . '/Settings.class.php' );
include_once( INSTALL_PATH . '/recLog.inc.php' );


// 予約クラス

class Reservation {
	
	public static function simple( $program_id , $autorec = 0, $mode = 0, $discontinuity=0 ) {
		global $settings;

		try {
			$prec       = new DBRecord( PROGRAM_TBL, 'id', $program_id );
			$start_time = toTimestamp( $prec->starttime );
			$end_time   = $end_org = toTimestamp( $prec->endtime );
			if( $autorec ){
				$keyword    = new DBRecord( KEYWORD_TBL, 'id', $autorec );
				$split_time = (int)$keyword->split_time;
				if( $split_time > 0 ){
					$filename   = $keyword->filename_format!=='' ? $keyword->filename_format : $settings->filename_format;
					$split_loop = (int)(( $end_time - $start_time ) / $split_time);
					do{
						$magic_c = strpos( $filename, '%TL_SB' );
						if( $magic_c !== FALSE ){
							$magic_c += 6;
							$tl_num   = 0;
							while( ctype_digit( $filename[$magic_c] ) )
								$tl_num = $tl_num * 10 + (int)$filename[$magic_c++];
							if( $tl_num>0 && $filename[$magic_c]==='%' ){
								$split_title = '%TL_SB';
								break;
							}
						}
						$magic_c = strpos( $filename, '%TITLE' );
						if( $magic_c!==FALSE && strpos( $filename, '%TITLE%' )===FALSE ){
							$magic_c += 6;
							$tl_num   = 0;
							while( ctype_digit( $filename[$magic_c] ) )
								$tl_num = $tl_num * 10 + (int)$filename[$magic_c++];
							if( $tl_num>0 && $filename[$magic_c]==='%' ){
								$split_title = '%TITLE';
								break;
							}
						}
						$split_title = '';
					}while(0);
				}else{
					$split_time  = $end_time - $start_time;
					$split_loop  = 1;
					$split_title = '';
				}
			}else{
				$split_time  = $end_time - $start_time;
				$split_loop  = 1;
				$split_title = '';
			}
			$loop    = 1;
			$bit_pic = 0x01;
			while(1){
				$end_time = $start_time + $split_time;
				if( ( (int)$prec->rec_ban_parts & $bit_pic ) === 0 ){		// 分割自動予約禁止フラグ確認
					if( $end_time > $end_org )
						$end_time = $end_org;
					if( $split_title==='%TITLE' && strpos( $prec->title, '/' )!==FALSE ){
						$split_tls = explode( '/', $prec->title );
						$title     = count($split_tls)>=$loop ? $split_tls[$loop-1] : $split_tls[count($split_tls)-1].'('.$loop.')';
					}else
					if( $split_title==='%TL_SB' && strpos( $prec->title, ' #' )!==FALSE ){
						list( $title, $sbtls ) = explode( ' #', $prec->title );
						if( strpos( $prec->title, '」#' ) !== FALSE ){
							$split_tls = explode( '」#', $sbtls );
							$title    .= ' #'.( count($split_tls)>=$loop ? $split_tls[$loop-1] : $split_tls[count($split_tls)-1].'('.$loop.')' );
							if( $loop < count( $split_tls ) )
								$title .= '」';
						}else{
							$split_tls = explode( '#', $sbtls );
							$title    .= ' #'.( count($split_tls)>=$loop ? $split_tls[$loop-1] : $split_tls[count($split_tls)-1].'('.$loop.')' );
						}
					}else{
						$title = $prec->title;
						if( $split_loop > 1 )
							$title .= '('.$loop.')';
					}
					$rval = 0;
					try {
						$rval = self::custom(
							toDatetime( $start_time ),
							toDatetime( $end_time ),
							$prec->channel_id,
							$title,
							$prec->description,
							$prec->category_id,
							$program_id,
							$autorec,
							$mode,
							$discontinuity );
					}catch( Exception $e ){
//						throw $e;
					}
				}
				if( $loop >= $split_loop )
					break;
				$loop++;
				$bit_pic  <<= 1;
				$start_time = $end_time;
			}
			if( $rval===0 && isset($e) )
				throw $e;
		}catch( Exception $e ) {
			throw $e;
		}
		return $rval.( strpos( $prec->description, '【終】' )!==FALSE ? ':1' : ':0' );
	}

	
	public static function custom(
		$starttime,				// 開始時間Datetime型
		$endtime,				// 終了時間Datetime型
		$channel_id,			// チャンネルID
		$title = 'none',		// タイトル
		$description = 'none',	// 概要
		$category_id = 0,		// カテゴリID
		$program_id = 0,		// 番組ID
		$autorec = 0,			// 自動録画ID
		$mode = 0,				// 録画モード
		$discontinuity = 0,		// 隣接禁止フラグ
		$dirty = 0,				// ダーティフラグ
		$man_priority = MANUAL_REV_PRIORITY	// 優先度
	) {
		global $rec_cmds,$OTHER_TUNERS_CHARA,$EX_TUNERS_CHARA;
		$settings = Settings::factory();
		$crec = new DBRecord( CHANNEL_TBL, 'id', $channel_id );
		// 時間を計算
		$start_time = toTimestamp( $starttime );
		$end_time   = toTimestamp( $endtime );
		$job = 0;
		try {
			if( $autorec ){
				$keyword  = new DBRecord( KEYWORD_TBL, 'id', $autorec );
				$priority = (int)$keyword->priority;
				$overlap  = (boolean)$keyword->overlap;

				// 同一番組予約チェック
				if( $program_id ){
					if( (int)$keyword->split_time === 0 ){
						$num = DBRecord::countRecords( RESERVE_TBL, 'WHERE program_id='.$program_id.' AND autorec='.$autorec );
						if( $num === 0 ){
							if( !$overlap ){
								$num = DBRecord::countRecords( RESERVE_TBL, 'WHERE program_id='.$program_id );
								if( $num ){
									$del_revs = DBRecord::createRecords( RESERVE_TBL, 'WHERE program_id='.$program_id.' AND priority<'.$priority );
									$num     -= count( $del_revs );
									if( $num <= 0 ){
										foreach( $del_revs as $rr )
											self::cancel( $rr->id );
										$num = 0;
									}
								}
							}else
								$num = DBRecord::countRecords( RESERVE_TBL, 'WHERE program_id='.$program_id.' AND overlap=0 AND priority>='.$priority );
						}
					}else{
						// 分割予約
						$num = DBRecord::countRecords( RESERVE_TBL, 'WHERE program_id='.$program_id.' AND autorec='.$autorec.
																		' AND starttime>="'.$starttime.'" AND endtime<="'.$endtime.'"' );
					}
					if( $num ){
						throw new Exception('同一の番組が録画予約されています');
					}
					$event = new DBRecord( PROGRAM_TBL, 'id', $program_id );
					$duration = toTimestamp( $event->endtime ) - toTimestamp( $event->starttime );
				}else
					$duration = $end_time - $start_time;
				if( (int)$keyword->criterion_dura && $duration!=(int)$keyword->criterion_dura ){
					if( (int)$keyword->criterion_dura > 1 ){
						$st_time = toTimestamp( $starttime );
						reclog( autoid_button($autorec).'にヒットした'.$crec->channel_disc.'-Ch'.$crec->channel.' <a href="index.php?type='.$crec->type.
								'&length='.$settings->program_length.'&time='.date( 'YmdH', ((int)$st_time/60)%60 ? $st_time : $st_time-1*60*60 ).'">'.$starttime.
								'</a>『'.htmlspecialchars($title).'』は、収録時間が'.
								($keyword->criterion_dura/60).'分間から'.($duration/60).'分間に変動しています。', EPGREC_WARN );
					}
					$keyword->criterion_dura = $duration;
					$keyword->update();
				}
				if( (boolean)$keyword->duration_chg ){
					if( (int)$keyword->sft_end >= 0 ){
						// 前方シフト+時間量
						$tmp_start = $start_time + (int)$keyword->sft_start;
						$tmp_end   = $tmp_start + (int)$keyword->sft_end;
					}else{
						// 末尾基準時間量
						$tmp_start = $end_time + (int)$keyword->sft_end;
						$tmp_end   = $end_time;
					}
				}else{
					$tmp_start = $start_time + (int)$keyword->sft_start;
					$tmp_end   = $end_time + (int)$keyword->sft_end;
				}
				if( $tmp_start<$tmp_end && $start_time<$tmp_end && $tmp_start<$end_time ){
					$start_time = $tmp_start;
					$end_time   = $tmp_end;
				}else
					throw new Exception( '時刻シフト量が異常なため、開始時刻が終了時刻以降に指定されています' );
			}else{
				$priority = (int)$man_priority;
				$overlap  = FALSE;
			}
			if( $start_time >= $end_time )
				throw new Exception( '開始時刻が終了時刻以降に指定されています' );

			$former_time     = (int)$settings->former_time;
			$extra_time      = (int)$settings->extra_time;
			$rec_switch_time = (int)$settings->rec_switch_time;
			$ed_tm_sft       = $former_time + $rec_switch_time;
			$ed_tm_sft_chk   = $ed_tm_sft + $extra_time;
			//チューナ仕様取得
			if( $crec->type === 'GR' ){
				$tuners   = (int)($settings->gr_tuners);
				$type_str = 'type=\'GR\'';
				$smf_type = 'GR';
			}else
			if( $crec->type === 'EX' ){
				$tuners   = EXTRA_TUNERS;
				$type_str = 'type=\'EX\'';
				$smf_type = 'EX';
			}else{
				$tuners   = (int)($settings->bs_tuners);
				$type_str = '(type=\'BS\' OR type=\'CS\')';
				$smf_type = 'BS';
			}
			$stt_str  = toDatetime( $start_time-$ed_tm_sft_chk );
			$end_str  = toDatetime( $end_time+$ed_tm_sft_chk );
			$battings = DBRecord::countRecords( RESERVE_TBL, 'WHERE complete=0 AND '.$type_str.
															' AND starttime<=\''.$end_str.
															'\' AND endtime>=\''.$stt_str.'\'' );		//重複数取得
			if( $battings > 0 ){
				//重複
				//予約群 先頭取得
				$res_obj    = new DBRecord( RESERVE_TBL );
				$prev_trecs = array();
				while( 1 ){
					try{
						$prev_trecs = $res_obj->fetch_array( 'complete', 0, $type_str.
															' AND starttime<\''.$stt_str.
															'\' AND endtime>=\''.$stt_str.'\' ORDER BY starttime ASC' );
						if( count($prev_trecs) == 0 )
							break;
						$stt_str = toDatetime( toTimestamp( $prev_trecs[0]['starttime'] )-$ed_tm_sft_chk );
					}catch( Exception $e ){
						break;
					}
				}
				//予約群 最後尾取得
				while( 1 ){
					try{
						$prev_trecs = $res_obj->fetch_array( 'complete', 0, $type_str.
															' AND starttime<=\''.$end_str.
															'\' AND endtime>\''.$end_str.'\' ORDER BY endtime DESC' );
						if( count($prev_trecs) == 0 )
							break;
						$end_str = toDatetime( toTimestamp( $prev_trecs[0]['endtime'] )+$ed_tm_sft_chk );
					}catch( Exception $e ){
						break;
					}
				}

				//重複予約配列取得
				$prev_trecs = $res_obj->fetch_array( 'complete', 0, $type_str.
															' AND starttime>=\''.$stt_str.
															'\' AND endtime<=\''.$end_str.'\'' );
//															'\' AND endtime<=\''.$end_str.'\' ORDER BY starttime ASC, endtime DESC' );
				// 予約修正に必要な情報を取り出す
				$trecs = array();
				for( $cnt=0; $cnt<count($prev_trecs) ; $cnt++ ){
					$trecs[$cnt]['id']            = (int)$prev_trecs[$cnt]['id'];
					$trecs[$cnt]['program_id']    = (int)$prev_trecs[$cnt]['program_id'];
					$trecs[$cnt]['channel_id']    = (int)$prev_trecs[$cnt]['channel_id'];
					$trecs[$cnt]['title']         = $prev_trecs[$cnt]['title'];
					$trecs[$cnt]['description']   = $prev_trecs[$cnt]['description'];
					$trecs[$cnt]['channel']       = (int)$prev_trecs[$cnt]['channel'];
					$trecs[$cnt]['category_id']   = (int)$prev_trecs[$cnt]['category_id'];
					$trecs[$cnt]['start_time']    = toTimestamp( $prev_trecs[$cnt]['starttime'] );
					$trecs[$cnt]['end_time']      = toTimestamp( $prev_trecs[$cnt]['endtime'] );
					$trecs[$cnt]['shortened']     = (boolean)$prev_trecs[$cnt]['shortened'];
					$trecs[$cnt]['end_time_sort'] = $trecs[$cnt]['shortened'] ? $trecs[$cnt]['end_time']+$ed_tm_sft : $trecs[$cnt]['end_time'];
					$trecs[$cnt]['autorec']       = (int)$prev_trecs[$cnt]['autorec'];
					$trecs[$cnt]['path']          = $prev_trecs[$cnt]['path'];
					$trecs[$cnt]['mode']          = (int)$prev_trecs[$cnt]['mode'];
					$trecs[$cnt]['dirty']         = (int)$prev_trecs[$cnt]['dirty'];
					$trecs[$cnt]['tuner']         = (int)$prev_trecs[$cnt]['tuner'];
					$trecs[$cnt]['priority']      = (int)$prev_trecs[$cnt]['priority'];
					$trecs[$cnt]['overlap']       = (boolean)$prev_trecs[$cnt]['overlap'];
					$trecs[$cnt]['discontinuity'] = (int)$prev_trecs[$cnt]['discontinuity'];
					$trecs[$cnt]['status']        = 1;
				}
				//新規予約を既予約配列に追加
				$trecs[$cnt]['id']            = 0;
				$trecs[$cnt]['program_id']    = $program_id;
				$trecs[$cnt]['channel_id']    = (int)$crec->id;
				$trecs[$cnt]['title']         = $title;
				$trecs[$cnt]['description']   = $description;
				$trecs[$cnt]['channel']       = (int)$crec->channel;
				$trecs[$cnt]['category_id']   = $category_id;
				$trecs[$cnt]['start_time']    = $start_time;
				$trecs[$cnt]['end_time']      = $end_time;
				$trecs[$cnt]['end_time_sort'] = $end_time;
				$trecs[$cnt]['shortened']     = FALSE;
				$trecs[$cnt]['autorec']       = $autorec;
				$trecs[$cnt]['path']          = '';
				$trecs[$cnt]['mode']          = (int)$mode;
				$trecs[$cnt]['dirty']         = $dirty;
				$trecs[$cnt]['tuner']         = -1;
				$trecs[$cnt]['priority']      = $priority;
				$trecs[$cnt]['overlap']       = $overlap;
				$trecs[$cnt]['discontinuity'] = $discontinuity;
				$trecs[$cnt]['status']        = 1;

				//全重複予約をソート
				foreach( $trecs as $key => $row ){
					$volume[$key]  = $row['start_time'];
					$edition[$key] = $row['end_time_sort'];
				}
				array_multisort( $volume, SORT_ASC, $edition, SORT_ASC, $trecs );

RETRY:;
				//予約配列参照用配列の初期化
				$r_cnt = 0;
				foreach( $trecs as $key => $row ){
					if( $row['status'] )
						$t_tree[0][$r_cnt++] = $key;
				}
				// 重複予約をチューナー毎に分配
				for( $t_cnt=0; $t_cnt<$tuners ; $t_cnt++ ){
					$b_rev = 0;
					$n_0 = 1;
					$n_1 = 0;
					if( isset( $t_tree[$t_cnt] ) )
					while( $n_0 < count($t_tree[$t_cnt]) ){
//file_put_contents( '/tmp/debug.txt', "[".count($t_tree[$t_cnt])."-".$n_0."]\n", FILE_APPEND );
						$af_st     = $trecs[$t_tree[$t_cnt][$n_0]]['start_time'];
//						$bf_st     = $trecs[$t_tree[$t_cnt][$b_rev]]['start_time'];
//						$bf_org_ed = $trecs[$t_tree[$t_cnt][$b_rev]]['end_time'];
						$bf_ed     = $trecs[$t_tree[$t_cnt][$b_rev]]['end_time_sort'];
						$variation = $af_st - $bf_ed;
						if( $variation<0 || ( ( $settings->force_cont_rec!=1 || $trecs[$t_tree[$t_cnt][$b_rev]]['discontinuity']==1 ) && $variation<$ed_tm_sft_chk ) ){
							//完全重複 隣接禁止時もここ
							$t_tree[$t_cnt+1][$n_1] = $t_tree[$t_cnt][$n_0];
							$n_1++;
//file_put_contents( '/tmp/debug.txt', ' '.count($t_tree[$t_cnt]).">", FILE_APPEND );
							array_splice( $t_tree[$t_cnt], $n_0, 1 );
//file_put_contents( '/tmp/debug.txt', count($t_tree[$t_cnt])."\n", FILE_APPEND );
						}else
						if( $variation < $ed_tm_sft_chk ){
							//隣接重複
							// 重複数算出
							$t_ovlp = 0;
//file_put_contents( '/tmp/debug.txt', ' $t_ovlp ', FILE_APPEND );
							if( isset( $t_tree[$t_cnt+1] ) ){
								foreach( $t_tree[$t_cnt+1] as $trunk ){
									if( $trecs[$trunk]['start_time']<=$bf_ed && $trecs[$trunk]['end_time_sort']>=$bf_ed )
										$t_ovlp++;
								}
//file_put_contents( '/tmp/debug.txt', $t_ovlp." -> ", FILE_APPEND );
							}
							$s_ch = -1;
							for( $br_lmt=$n_0; $br_lmt<count($t_tree[$t_cnt]); $br_lmt++ ){
								//同じ開始時間の物をカウント
								$variation = $trecs[$t_tree[$t_cnt][$br_lmt]]['start_time'] - $bf_ed;
								if( 0<=$variation && $variation<$ed_tm_sft_chk ){
									$t_ovlp++;
									//同じCh
									if( $trecs[$t_tree[$t_cnt][$b_rev]]['channel_id'] === $trecs[$t_tree[$t_cnt][$br_lmt]]['channel_id'] )
										$s_ch = $br_lmt;
								}else
									break;
							}
//file_put_contents( '/tmp/debug.txt', $t_ovlp."\n", FILE_APPEND );

							if( $t_ovlp<=$tuners-$t_cnt || ( $settings->force_cont_rec==1 && $trecs[$t_tree[$t_cnt][$b_rev]]['discontinuity']!=1 ) ){
//file_put_contents( '/tmp/debug.txt', ' '.count($t_tree[$t_cnt]).">>\n", FILE_APPEND );
								if( $t_ovlp<=TUNER_UNIT1-1-$t_cnt && $t_ovlp <= $tuners-1-$t_cnt ){
									//(使い勝手の良い)チューナに余裕あり
									for( $cc=$n_0; $cc<$br_lmt; $cc++ ){
										$t_tree[$t_cnt+1][$n_1] = $t_tree[$t_cnt][$cc];
										$n_1++;
									}
//file_put_contents( '/tmp/debug.txt', " array1-(".($br_lmt-$n_0).")\n", FILE_APPEND );
									array_splice( $t_tree[$t_cnt], $n_0, $br_lmt-$n_0 );
								}else{
									//チューナに余裕なし
									if( $s_ch !== -1 ){
										//同じCh同士を隣接 いらんかな？
										for( $cc=$n_0; $cc<$s_ch; $cc++ ){
											$t_tree[$t_cnt+1][$n_1] = $t_tree[$t_cnt][$cc];
											$n_1++;
										}
										for( $cc=$s_ch+1; $cc<$br_lmt; $cc++ ){
											$t_tree[$t_cnt+1][$n_1] = $t_tree[$t_cnt][$cc];
											$n_1++;
										}
//file_put_contents( '/tmp/debug.txt', " array2-1-(".$t_ovlp." ".$br_lmt." ".$s_ch." ".$n_0.")\n", FILE_APPEND );
//file_put_contents( '/tmp/debug.txt', " array2-2-(".($br_lmt-($s_ch+1)).")\n", FILE_APPEND );
										if( $br_lmt-($s_ch+1) > 0 )
											array_splice( $t_tree[$t_cnt], $s_ch+1, $br_lmt-($s_ch+1) );
//file_put_contents( '/tmp/debug.txt', " array2-3-(".($s_ch-$n_0).")\n", FILE_APPEND );
										if( $s_ch-$n_0 > 0 )
											array_splice( $t_tree[$t_cnt], $n_0, $s_ch-$n_0 );
										$b_rev++;
										$n_0++;
									}else{
										//頭の予約を隣接
										$b_rev++;
										$n_0++;
										for( $cc=$n_0; $cc<$br_lmt; $cc++ ){
											$t_tree[$t_cnt+1][$n_1] = $t_tree[$t_cnt][$cc];
											$n_1++;
										}
//file_put_contents( '/tmp/debug.txt', " array3A-(".($br_lmt-$n_0).")\n", FILE_APPEND );
										if( $br_lmt-$n_0 > 0 )
											array_splice( $t_tree[$t_cnt], $n_0, $br_lmt-$n_0 );
									}
								}
							}else
								goto PRIORITY_CHECK;
//file_put_contents( '/tmp/debug.txt', "  >>".count($t_tree[$t_cnt])."\n", FILE_APPEND );
						}else{
							//隣接なし
							$b_rev++;
							$n_0++;
//file_put_contents( '/tmp/debug.txt', "  <<<".count($t_tree[$t_cnt]).">>>\n", FILE_APPEND );
						}
//file_put_contents( '/tmp/debug.txt', " [[".count($t_tree[$t_cnt])."-".$n_0."]]\n", FILE_APPEND );
					}
				}
//file_put_contents( '/tmp/debug.txt', "分配完了\n\n", FILE_APPEND );
//var_dump($t_tree);
				//重複解消不可処理
				if( count($t_tree) > $tuners ){
PRIORITY_CHECK:
					if( $autorec ){
						//優先度判定
						$pri_ret = $res_obj->fetch_array( 'complete', 0, $type_str.' AND priority<'.$priority.
															' AND starttime<=\''.toDatetime($end_time).
															'\' AND endtime>=\''.toDatetime($start_time).'\' ORDER BY priority ASC, starttime ASC' );
						if( count( $pri_ret ) ){
							foreach( $pri_ret as $pri_chk )
								for( $cnt=0; $cnt<count($trecs) ; $cnt++ )
									if( $trecs[$cnt]['id'] === (int)$pri_chk['id'] ){
										if( $trecs[$cnt]['status'] ){
											//優先度の低い予約を仮無効化
											$trecs[$cnt]['status'] = 0;
											unset( $t_tree );
											goto RETRY;
										}else
											continue 2;
									}
						}
						//自動予約禁止
						$event = new DBRecord( PROGRAM_TBL, 'id', $program_id );
//						if( (int)$event->key_id!==0 && (int)$event->key_id!==$autorec && DBRecord::countRecords( KEYWORD_TBL, 'WHERE id='.$event->key_id )!==0 )
						if( (int)$event->key_id!==0 && ( (int)$event->key_id===$autorec
												|| ( (int)$event->key_id!==$autorec && DBRecord::countRecords( KEYWORD_TBL, 'WHERE id='.$event->key_id )!==0 ) ) )
							goto LOG_THROW;
						$event->key_id = $autorec;
						$event->update();
						$st_time = toTimestamp( $starttime );
						reclog( autoid_button($autorec).'にヒットした'.$crec->channel_disc.'-Ch'.$crec->channel.' <a href="index.php?type='.$crec->type.
								'&length='.$settings->program_length.'&time='.date( 'YmdH', ((int)$st_time/60)%60 ? $st_time : $st_time-1*60*60 ).'">'.$starttime.
								'</a>『'.htmlspecialchars($title).'』は重複により予約できません', EPGREC_WARN );
LOG_THROW:;
					}
					throw new Exception( '重複により予約できません' );
				}
// file_put_contents( '/tmp/debug.txt', "重複解消\n", FILE_APPEND );
				//チューナ番号の解決
				$t_blnk        = array_fill( 0, $tuners, 0 );
				$t_num         = array_fill( 0, $tuners, -1 );
				$tuner_no      = array_fill( 0, $tuners, -1 );
				$tuner_cnt     = array_fill( 0, $tuners, -1 );
				$tree_lmt      = count( $t_tree );
				$division_mode = 0;
				//録画中のチューナ番号取得
				for( $tree_cnt=0; $tree_cnt<$tree_lmt; $tree_cnt++ )
					if( $trecs[$t_tree[$tree_cnt][0]]['id'] !== 0 ){
						$prev_start_time = $trecs[$t_tree[$tree_cnt][0]]['start_time'] - $former_time;
						if( time() >= $prev_start_time ){
							$t_num[$tree_cnt]          = $trecs[$t_tree[$tree_cnt][0]]['tuner'];
							$t_blnk[$t_num[$tree_cnt]] = 2;
							$division_mode             = 1;
						}
					}
				//チューナー毎の予約配列中で多数使用しているチューナー番号を採用・重複時は早い者勝ち
				for( $tree_cnt=0; $tree_cnt<$tree_lmt; $tree_cnt++ )
					if( $t_num[$tree_cnt] === -1 ){
						$stk = array_fill( 0, $tuners, 0 );
						//各チューナーの予約数集計
						for( $rv_cnt=0; $rv_cnt<count($t_tree[$tree_cnt]); $rv_cnt++ ){
							$tmp_tuner = $trecs[$t_tree[$tree_cnt][$rv_cnt]]['tuner'];
							if( $tmp_tuner !== -1 )
								$stk[$tmp_tuner]++;
						}
						//予約数最多のチューナー番号を選択
						for( $tuner_c=0; $tuner_c<$tuners; $tuner_c++ )
							if( $t_blnk[$tuner_c]!==2 && $stk[$tuner_c] > $tuner_cnt[$tree_cnt] ){
								$tuner_no[$tree_cnt]  = $tuner_c;
								$tuner_cnt[$tree_cnt] = $stk[$tuner_c];
							}
					}
				//指定チューナー番号を最多指定している予約配列に仮決定
				for( $tuner_c=0; $tuner_c<$tuners; $tuner_c++ )
					if( $t_blnk[$tuner_c] !== 2 ){
						$tmp_cnt  = 0;
						$tmp_tree = -1;
						for( $tree_cnt=0; $tree_cnt<$tree_lmt; $tree_cnt++ )
							if( $tuner_no[$tree_cnt]===$tuner_c && $tuner_cnt[$tree_cnt]>$tmp_cnt ){
								$tmp_cnt  = $tuner_cnt[$tree_cnt];
								$tmp_tree = $tree_cnt;
							}
						if( $tmp_tree !== -1 ){
							$t_num[$tmp_tree] = $tuner_c;
							$t_blnk[$tuner_c] = 1;
						}
					}
				for( $tree_cnt=0; $tree_cnt<$tree_lmt; $tree_cnt++ )
					//未決定な配列への空番号割り当て
					if( $t_num[$tree_cnt] === -1 ){
						for( $tuner_c=0; $tuner_c<$tuners; $tuner_c++ )
							if( !$t_blnk[$tuner_c] ){
								$t_num[$tree_cnt] = $tuner_c;
								$t_blnk[$tuner_c] = 1;
								break;
							}
					}else
						//前に空がありハード的にチューナーが変更される場合のみチューナー番号変更
						if( $t_num[$tree_cnt]>=TUNER_UNIT1 && $t_num[$tree_cnt]>=$tree_lmt )
							for( $tuner_c=0; $tuner_c<TUNER_UNIT1; $tuner_c++ )
								if( !$t_blnk[$tuner_c] ){
									if( $t_blnk[$t_num[$tree_cnt]] !== 2 ){
										$t_blnk[$t_num[$tree_cnt]] = 0;
										$t_num[$tree_cnt]          = $tuner_c;
										$t_blnk[$tuner_c]          = 1;
									}else
										//録画中の予約以外を別配列に移動
										if( $tree_lmt < $tuners ){
											$t_tree[$tree_lmt] = array_slice( $t_tree[$tree_cnt], 1 );
											array_splice( $t_tree[$tree_cnt], 1 );
											$t_num[$tree_lmt++] = $tuner_c;
											$t_blnk[$tuner_c]   = 1;
										}
									break;
								}
				//優先度判定で削除になった予約をキャンセル
				foreach( $trecs as $sel )
					if( !$sel['status'] ){
						self::cancel( $sel['id'] );
					}
				$tuner_chg = 0;
				//新規予約・隣接解消再予約等 隣接禁止については分配時に解決済
				for( $t_cnt=0; $t_cnt<$tuners ; $t_cnt++ ){
// file_put_contents( '/tmp/debug.txt', ($t_cnt+1)."(".count($t_tree[$t_cnt]).")\n", FILE_APPEND );
//var_dump($t_tree[$t_cnt]);
					if( isset( $t_tree[$t_cnt] ) )
					for( $n_0=0,$n_lmt=count($t_tree[$t_cnt]); $n_0<$n_lmt ; $n_0++ ){
						// 予約修正に必要な情報を取り出す
						$prev_id            = $trecs[$t_tree[$t_cnt][$n_0]]['id'];
						$prev_program_id    = $trecs[$t_tree[$t_cnt][$n_0]]['program_id'];
						$prev_channel_id    = $trecs[$t_tree[$t_cnt][$n_0]]['channel_id'];
						$prev_title         = $trecs[$t_tree[$t_cnt][$n_0]]['title'];
						$prev_description   = $trecs[$t_tree[$t_cnt][$n_0]]['description'];
						$prev_channel       = $trecs[$t_tree[$t_cnt][$n_0]]['channel'];
						$prev_category_id   = $trecs[$t_tree[$t_cnt][$n_0]]['category_id'];
						$prev_start_time    = $trecs[$t_tree[$t_cnt][$n_0]]['start_time'];
						$prev_end_time      = $trecs[$t_tree[$t_cnt][$n_0]]['end_time'];
						$prev_shortened     = $trecs[$t_tree[$t_cnt][$n_0]]['shortened'];
						$prev_autorec       = $trecs[$t_tree[$t_cnt][$n_0]]['autorec'];
						$prev_path          = $trecs[$t_tree[$t_cnt][$n_0]]['path'];
						$prev_mode          = $trecs[$t_tree[$t_cnt][$n_0]]['mode'];
						$prev_dirty         = $trecs[$t_tree[$t_cnt][$n_0]]['dirty'];
						$prev_tuner         = $trecs[$t_tree[$t_cnt][$n_0]]['tuner'];
						$prev_priority      = $trecs[$t_tree[$t_cnt][$n_0]]['priority'];
						$prev_overlap       = $trecs[$t_tree[$t_cnt][$n_0]]['overlap'];
						$prev_discontinuity = $trecs[$t_tree[$t_cnt][$n_0]]['discontinuity'];
						if( $n_0 < $n_lmt-1 )
							$next_start_time = $trecs[$t_tree[$t_cnt][$n_0+1]]['start_time'];
						if( $prev_id === 0 ){
							//新規予約
							if( $n_0<$n_lmt-1 && $prev_end_time+$ed_tm_sft_chk>$next_start_time ){
								$prev_end_time -= $ed_tm_sft;
								$prev_shortened = TRUE;
							}
							try {
								$job = self::at_set( 
									$prev_start_time,			// 開始時間Datetime型
									$prev_end_time,				// 終了時間Datetime型
									$prev_channel_id,			// チャンネルID
									$prev_title,				// タイトル
									$prev_description,			// 概要
									$prev_category_id,			// カテゴリID
									$prev_program_id,			// 番組ID
									$prev_autorec,				// 自動録画
									$prev_mode,
									$prev_dirty,
									$t_num[$t_cnt],				// チューナ
									$prev_priority,
									$prev_overlap,
									$prev_discontinuity,
									$prev_shortened
									);
							}
							catch( Exception $e ) {
								throw new Exception( '新規予約できません' );
							}
							continue;
						}else
							if( time() < $prev_start_time-$former_time ){
								//録画開始前
								if( $prev_tuner !== $t_num[$t_cnt] )
									$tuner_chg = 1;
								$shortened_clear = FALSE;
								if( $n_0 < $n_lmt-1 ){
									if( !$prev_shortened ){
										if( $prev_end_time > $next_start_time-$ed_tm_sft_chk ){
											//隣接解消再予約
											$prev_end_time -= $ed_tm_sft;
											$prev_shortened = TRUE;
											try {
												// いったん予約取り消し
												self::cancel( $prev_id );
												// 再予約
												$rval = self::at_set( 
													$prev_start_time,			// 開始時間Datetime型
													$prev_end_time,				// 終了時間Datetime型
													$prev_channel_id,			// チャンネルID
													$prev_title,				// タイトル
													$prev_description,			// 概要
													$prev_category_id,			// カテゴリID
													$prev_program_id,			// 番組ID
													$prev_autorec,				// 自動録画
													$prev_mode,
													$prev_dirty,
													$t_num[$t_cnt],				// チューナ
													$prev_priority,
													$prev_overlap,
													$prev_discontinuity,
													$prev_shortened
													);
											}
											catch( Exception $e ) {
												if( $prev_autorec == 0 ){
													// 手動予約のトラコン設定削除
													$tran_ex = DBRecord::createRecords( TRANSEXPAND_TBL, 'WHERE key_id=0 AND type_no='.$prev_id );
													foreach( $tran_ex as $tran_set )
														$tran_set->delete();
												}
												throw new Exception( '予約できません' );
											}
											if( $prev_autorec == 0 ){
												// 手動予約のトラコン設定の予約ID修正
												list( , , $rec_id, ) = explode( ':', $rval );
												$tran_ex = DBRecord::createRecords( TRANSEXPAND_TBL, 'WHERE key_id=0 AND type_no='.$prev_id );
												foreach( $tran_ex as $tran_set ){
													$tran_set->type_no = $rec_id;
													$tran_set->update();
												}
											}
											continue;
										}
									}else{
										if( $prev_end_time+$ed_tm_sft+$ed_tm_sft_chk <= $next_start_time ){
											//終了時間短縮解消再予約
											$prev_end_time += $ed_tm_sft;
											$prev_shortened = FALSE;
											try {
												// いったん予約取り消し
												self::cancel( $prev_id );
												// 再予約
												$rval = self::at_set( 
													$prev_start_time,			// 開始時間Datetime型
													$prev_end_time,				// 終了時間Datetime型
													$prev_channel_id,			// チャンネルID
													$prev_title,				// タイトル
													$prev_description,			// 概要
													$prev_category_id,			// カテゴリID
													$prev_program_id,			// 番組ID
													$prev_autorec,				// 自動録画
													$prev_mode,
													$prev_dirty,
													$t_num[$t_cnt],				// チューナ
													$prev_priority,
													$prev_overlap,
													$prev_discontinuity,
													$prev_shortened
													);
											}
											catch( Exception $e ) {
												if( $prev_autorec == 0 ){
													// 手動予約のトラコン設定削除
													$tran_ex = DBRecord::createRecords( TRANSEXPAND_TBL, 'WHERE key_id=0 AND type_no='.$prev_id );
													foreach( $tran_ex as $tran_set )
														$tran_set->delete();
												}
												throw new Exception( '予約できません' );
											}
											if( $prev_autorec == 0 ){
												// 手動予約のトラコン設定の予約ID修正
												list( , , $rec_id, ) = explode( ':', $rval );
												$tran_ex = DBRecord::createRecords( TRANSEXPAND_TBL, 'WHERE key_id=0 AND type_no='.$prev_id );
												foreach( $tran_ex as $tran_set ){
													$tran_set->type_no = $rec_id;
													$tran_set->update();
												}
											}
											continue;
										}
									}
								}else
									if( $prev_shortened ){
										// 条件が不足してるかも
										$prev_end_time  += $ed_tm_sft;
										$prev_shortened  = FALSE;
										$shortened_clear = TRUE;
									}
								//チューナ変更処理+末尾evennt短縮解消
								if( $prev_tuner!==$t_num[$t_cnt] || $shortened_clear ){
									try {
										// いったん予約取り消し
										self::cancel( $prev_id );
										// 再予約
										$rval = self::at_set( 
											$prev_start_time,			// 開始時間Datetime型
											$prev_end_time,				// 終了時間Datetime型
											$prev_channel_id,			// チャンネルID
											$prev_title,				// タイトル
											$prev_description,			// 概要
											$prev_category_id,			// カテゴリID
											$prev_program_id,			// 番組ID
											$prev_autorec,				// 自動録画
											$prev_mode,
											$prev_dirty,
											$t_num[$t_cnt],				// チューナ
											$prev_priority,
											$prev_overlap,
											$prev_discontinuity,
											$prev_shortened
											);
									}
									catch( Exception $e ) {
										if( $prev_autorec == 0 ){
											// 手動予約のトラコン設定削除
											$tran_ex = DBRecord::createRecords( TRANSEXPAND_TBL, 'WHERE key_id=0 AND type_no='.$prev_id );
											foreach( $tran_ex as $tran_set )
												$tran_set->delete();
										}
										throw new Exception( 'チューナ機種の変更に失敗' );
									}
									if( $prev_autorec == 0 ){
										// 手動予約のトラコン設定の予約ID修正
										list( , , $rec_id, ) = explode( ':', $rval );
										$tran_ex = DBRecord::createRecords( TRANSEXPAND_TBL, 'WHERE key_id=0 AND type_no='.$prev_id );
										foreach( $tran_ex as $tran_set ){
											$tran_set->type_no = $rec_id;
											$tran_set->update();
										}
									}
								}
							}else{
								if( $smf_type === 'EX' )
									$cmd_num = $EX_TUNERS_CHARA[$prev_tuner]['reccmd'];
								else
									$cmd_num = $prev_tuner<TUNER_UNIT1 ? PT1_CMD_NUM : $OTHER_TUNERS_CHARA[$smf_type][$prev_tuner-TUNER_UNIT1]['reccmd'];
								if( $n_0===0 && $n_lmt>1 && $rec_cmds[$cmd_num]['cntrl'] ){
									//録画中
									if( !$prev_shortened ){
										if( $prev_end_time > $next_start_time-$ed_tm_sft_chk ){
											//録画時間短縮指示
											$ps = search_reccmd( $prev_id );
											if( $ps !== FALSE ){
												exec( RECPT1_CTL.' --pid '.$ps->pid.' --extend -'.($ed_tm_sft+$extra_time) );
												for( $i=0; $i<count($prev_trecs) ; $i++ ){
													if( $prev_id === (int)$prev_trecs[$i]['id'] ){
														$wrt_set = array();
														$wrt_set['endtime']   = toDatetime( $prev_end_time - $ed_tm_sft );
														$wrt_set['shortened'] = TRUE;
														$res_obj->force_update( $prev_trecs[$i]['id'], $wrt_set );
														break;
													}
												}
											}
										}
									}else{
										if( $prev_end_time+$ed_tm_sft+$ed_tm_sft_chk <= $next_start_time ){
											//録画時間延伸指示
											$ps = search_reccmd( $prev_id );
											if( $ps !== FALSE ){
												exec( RECPT1_CTL.' --pid '.$ps->pid.' --extend '.($ed_tm_sft+$extra_time) );
												for( $i=0; $i<count($prev_trecs) ; $i++ ){
													if( $prev_id === (int)$prev_trecs[$i]['id'] ){
														$wrt_set = array();
														$wrt_set['endtime']   = toDatetime( $prev_end_time + $ed_tm_sft );
														$wrt_set['shortened'] = FALSE;
														$res_obj->force_update( $prev_trecs[$i]['id'], $wrt_set );
														break;
													}
												}
											}
										}
									}
								}
							}
					}
				}
				return $job.':'.$tuner_chg;			// 成功
			}else{
				//単純予約
				try {
					$job = self::at_set(
						$start_time,
						$end_time,
						$channel_id,
						$title,
						$description,
						$category_id,
						$program_id,
						$autorec,
						(int)$mode,
						$dirty,
						0,		// チューナー番号
						$priority,
						$overlap,
						$discontinuity,
						FALSE
					);
				}
				catch( Exception $e ) {
					throw new Exception( '予約できません' );
				}
				return $job.':0';			// 成功
			}
		}
		catch( Exception $e ) {
			throw $e;
		}
	}
	// custom 終了

	private static function at_set(
		$start_time,				// 開始時間
		$end_time,				// 終了時間
		$channel_id,			// チャンネルID
		$title = 'none',		// タイトル
		$description = 'none',	// 概要
		$category_id = 0,		// カテゴリID
		$program_id = 0,		// 番組ID
		$autorec = 0,			// 自動録画ID
		$mode = 0,				// 録画モード
		$dirty = 0,				// ダーティフラグ
		$tuner = 0,				// チューナ
		$priority,				// 優先度
		$overlap,				// 重複予約可否
		$discontinuity,			// 隣接短縮可否
		$shortened				// 隣接短縮フラグ
	) {
		global $RECORD_MODE,$rec_cmds,$OTHER_TUNERS_CHARA,$EX_TUNERS_CHARA;
		$settings   = Settings::factory();
		$spool_path = INSTALL_PATH.$settings->spool;
		$crec_      = new DBRecord( CHANNEL_TBL, 'id', $channel_id );
		$smf_type   = $crec_->type!=='CS' ? $crec_->type : 'BS';

		//即時録画の指定チューナー確保
		$epg_time = array( 'GR' => FIRST_REC, 'BS' => 180, 'CS' => 120, 'EX' => 180 );
		if( $start_time-$settings->former_time-$epg_time[$crec_->type] <= time() ){
			$shm_nm   = array( SEM_GR_START, SEM_ST_START, SEM_EX_START );
			switch( $crec_->type ){
				case 'GR':
					$sem_type = 0;
					break;
				case 'BS':
				case 'CS':
					$sem_type = 1;
					break;
				case 'EX':
					$sem_type = 2;
					break;
			}
			$shm_name = $shm_nm[$sem_type] + $tuner;
			$sem_id   = sem_get_surely( $shm_name );
			if( $sem_id === FALSE )
				throw new Exception( 'セマフォ・キー確保に失敗' );
			$cc=0;
			while(1){
				if( sem_acquire( $sem_id ) === TRUE ){
					$shm_id = shmop_open_surely();
					$smph   = shmop_read_surely( $shm_id, $shm_name );
					if( $smph == 2 ){
						// リアルタイム視聴停止
						$real_view = (int)trim( file_get_contents( REALVIEW_PID ) );
						unlink( REALVIEW_PID );
						posix_kill( $real_view, 9 );		// 録画コマンド停止
						shmop_write_surely( $shm_id, $shm_name, 0 );
						shmop_write_surely( $shm_id, SEM_REALVIEW, 0 );		// リアルタイム視聴tunerNo clear
						shmop_close( $shm_id );
						$sleep_time = $settings->rec_switch_time;
					}else
						if( $smph == 1 ){
							// EPG受信停止
							$rec_trace = $settings->temp_data.'_'.$smf_type.$tuner;
							$ps_output = shell_exec( PS_CMD );
							$rarr      = explode( "\n", $ps_output );
							for( $cc=0; $cc<count($rarr); $cc++ ){
								if( strpos( $rarr[$cc], $rec_trace ) !== FALSE ){
									$ps = ps_tok( $rarr[$cc] );
									while( ++$cc < count($rarr) ){
										$c_ps = ps_tok( $rarr[$cc] );
										if( $ps->pid == $c_ps->ppid ){
											$ps = $c_ps;
											while( ++$cc < count($rarr) ){
												$c_ps = ps_tok( $rarr[$cc] );
												if( $ps->pid == $c_ps->ppid ){
													posix_kill( $c_ps->pid, 15 );		//EPG受信停止
													$sleep_time = $settings->rec_switch_time;
													break 4;
												}
											}
										}
									}
									$sleep_time = $settings->rec_switch_time;
									break 2;
								}
							}
						}
					break;
				}else
					if( ++$cc < 5 )
						sleep(1);
					else
						throw new Exception( 'チューナー確保に失敗' );
			}
		}

		//時間がらみ調整
		$now_time = time();
		if( $start_time-$settings->former_time <= $now_time ){	// すでに開始されている番組
			$at_start = $now_time;
			if( isset( $sleep_time ) )
				$now_time += $sleep_time;
			else
				$sleep_time = 0;
			$rec_start = $start_time = $now_time;		// 即開始
		}else{
			if( $now_time < $end_time ){
				$rec_start  = $start_time - $settings->former_time;
				$padding_tm = $start_time%60 ? PADDING_TIME+$start_time%60 : PADDING_TIME;
				$at_start   = ( $start_time-$padding_tm <= $now_time ) ? $now_time : $start_time - $padding_tm;
				$sleep_time = $rec_start - $at_start;
			}else
				throw new Exception( '終わっている番組です' );
		}
		$duration = $end_time - $rec_start;
		if( $duration < $settings->former_time ) {	// 終了間際の番組は弾く
			throw new Exception( '終わりつつある/終わっている番組です' );
		}
		if( $program_id ){
			$prg = new DBRecord( PROGRAM_TBL, 'id', $program_id );
			$resolution = (int)(($prg->video_type & 0xF0) >> 4 );
			$aspect     = (int)$prg->video_type & 0x0F;
			$audio_type = (int)$prg->audio_type;
			$bilingual  = (int)$prg->multi_type;
			$eid        = (int)$prg->eid;
			$sub_genre  = (int)$prg->sub_genre;
			if( $autorec )
				$keyword = new DBRecord( KEYWORD_TBL, 'id', $autorec );
			$prg->key_id = 0;	// 自動予約禁止解除
			$prg->update();
		}else{
			$resolution = 0;
			$aspect     = 0;
			$audio_type = 0;
			$bilingual  = 0;
			$eid        = 0;
			$sub_genre  = 16;
		}
		if( !$shortened )
			$duration += $settings->extra_time;			//重複による短縮がされてないものは糊代を付ける
		$rrec = null;
		try {
			// ここからファイル名生成
/*
			%TITLE%	番組タイトル
			// %TITLEn%	番組タイトル(n=1-9 1枠の複数タイトルから選別変換 '/'でセパレートされているものとする)
			%ST%	開始日時（ex.200907201830)
			%ET%	終了日時
			%TYPE%	GR/BS/CS
			%CH%	チャンネル番号
			// %SID%	サービスID
			// %CHNAME%	チャンネル名
			%DOW%	曜日（Sun-Mon）
			%DOWJ%	曜日（日-土）
			%YEAR%	開始年
			%MONTH%	開始月
			%DAY%	開始日
			%HOUR%	開始時
			%MIN%	開始分
			%SEC%	開始秒
			%DURATION%	録画時間（秒）
			// %DURATIONHMS%	録画時間（hh:mm:ss）
*/
			$day_of_week = array( '日','月','火','水','木','金','土' );
			$filename = $autorec&&$keyword->filename_format!='' ? $keyword->filename_format : $settings->filename_format;

			$out_title = trim($title);
			// %TITLE%
			$filename = mb_str_replace('%TITLE%', $out_title, $filename);
			// %TITLEn%	番組タイトル(n=1-9 1枠の複数タイトルから選別変換 '/'でセパレートされているものとする)
			while(1){
				$magic_c = strpos( $filename, '%TITLE' );
				if( $magic_c !== FALSE ){
					$tl_num = $filename[$magic_c+6];
					if( ctype_digit( $tl_num ) && $filename[$magic_c+7]==='%' ){
						if( strpos( $out_title, '/' )!==FALSE ){
							$split_tls = explode( '/', $out_title );
							$filename  = mb_str_replace( '%TITLE'.$tl_num.'%', $split_tls[(int)$tl_num-1], $filename );
						}else
							$filename = mb_str_replace( '%TITLE'.$tl_num.'%', $out_title, $filename );
					}else
						break;
				}else
					break;
			}
			// %TL_SBn%	タイトル+複数話分割(n=1-n 1枠の複数サブタイトルから選別変換)
			while(1){
				$magic_c = strpos( $filename, '%TL_SB' );
				if( $magic_c !== FALSE ){
					$magic_c += 6;
					$tl_num   = 0;
					while( ctype_digit( $filename[$magic_c] ) )
						$tl_num = $tl_num * 10 + (int)$filename[$magic_c++];
					if( $tl_num>0 && $filename[$magic_c]==='%' ){
						if( strpos( $out_title, '」#' ) !== FALSE ){
							list( $pictitle, $sbtls ) = explode( ' #', $out_title );
							$split_tls = explode( '」#', $sbtls );
							$pictitle .= ' #'.$split_tls[$tl_num-1];
							if( $tl_num < count( $split_tls ) )
								$pictitle .= '」';
							$filename = mb_str_replace( '%TL_SB'.$tl_num.'%', $pictitle, $filename );
						}else
							$filename = mb_str_replace( '%TL_SB'.$tl_num.'%', $out_title, $filename );
					}else
						break;
				}else
					break;
			}
			// %ST%	開始日時
			$filename = mb_str_replace('%ST%',date('YmdHis', $start_time), $filename );
			// %ET%	終了日時
			$filename = mb_str_replace('%ET%',date('YmdHis', $end_time), $filename );
			// %TYPE%	GR/BS
			$filename = mb_str_replace('%TYPE%',$crec_->type, $filename );
			// %SID%	サービスID
			$filename = mb_str_replace('%SID%',$crec_->sid, $filename );
			// %CH%	チャンネル番号
			$filename = mb_str_replace('%CH%',$crec_->channel, $filename );
			// %CH2%	チャンネル番号(その2) %TYPE%が不要になる
			$filename = mb_str_replace('%CH2%',$crec_->channel_disc, $filename );
			// %CH3%	チャンネル番号(その3) マルチチャンネルが無い場合はこちらが良いかも
			if( strpos( $filename, '%CH3%' ) !== FALSE ){
				$ch_num   = $crec_->type==='GR' ? $crec_->channel : $crec_->sid;
				$filename = mb_str_replace('%CH3%',$ch_num, $filename );
			}
			// %CHNAME%	チャンネル名
			$filename = mb_str_replace('%CHNAME%',$crec_->name, $filename );
			// %DOW%	曜日（Sun-Mon）
			$filename = mb_str_replace('%DOW%',date('D', $start_time), $filename );
			// %DOWJ%	曜日（日-土）
			$filename = mb_str_replace('%DOWJ%',$day_of_week[(int)date('w', $start_time)], $filename );
			// %YEAR%	開始年
			$filename = mb_str_replace('%YEAR%',date('Y', $start_time), $filename );
			// %MONTH%	開始月
			$filename = mb_str_replace('%MONTH%',date('m', $start_time), $filename );
			// %DAY%	開始日
			$filename = mb_str_replace('%DAY%',date('d', $start_time), $filename );
			// %HOUR%	開始時
			$filename = mb_str_replace('%HOUR%',date('H', $start_time), $filename );
			// %MIN%	開始分
			$filename = mb_str_replace('%MIN%',date('i', $start_time), $filename );
			// %SEC%	開始秒
			$filename = mb_str_replace('%SEC%',date('s', $start_time), $filename );
			// %DURATION%	録画時間（秒）
			$filename = mb_str_replace('%DURATION%',$duration, $filename );
			// %DURATIONHMS% %DURAHMS%	録画時間（hh:mm:ss）
			$filename = mb_str_replace('%DURATIONHMS%',transTime($duration,TRUE), $filename );
			$filename = mb_str_replace('%DURAHMS%',transTime($duration,TRUE), $filename );
			// %%[YmdHisD]*%%	開始日時('%%'に挟まれた部分をそのまま書式としてPHP関数date()に渡す 非変換部に'%%'を使う場合は誤変換に注意・対策はしない)
			if( substr_count( $filename, '%%' ) === 2 ){
				$split_tls = explode( '%%', $filename );
				$tran_date = date( $split_tls[1], $start_time );
				if( $tran_date!==FALSE && $tran_date!==$split_tls[1] )
					$filename = mb_str_replace( '%%'.$split_tls[1].'%%', $tran_date, $filename );
			}
			// %DATE(A)%	開始日時(任意指定) 文字列Aをそのまま書式としてPHP関数date()に渡す
			while(1){
				$csv_word = operateParse( $filename, 'DATE' );
				if( $csv_word !== FALSE ){
					$tran_date = date( $csv_word, $start_time );
					if( $tran_date!==FALSE && $tran_date!==$csv_word )
						$filename = str_replace( '%DATE('.$csv_word.')%', $tran_date, $filename );
				}else
					break;
			}
			// %DESC% 番組概要
			if( strpos( $filename, '%DESC%' ) !== FALSE )
				$filename = str_replace( '%DESC%', trim($description), $filename );
			// %DESC(n1,A,n2)%		番組概要の部分取得
			// %TITLE(n1,A,n2)%		番組タイトルの部分取得
			//					一部省略する場合はカンマをつけない事 ex:%DESC(n1,A)%
			//		n1(=nn)		取得byte数 負数の場合は後方から数える。0の場合は指定領域の全体を対象とする。
			//		A			区切り文字列(省略化、その場合はn2も省略すること)
			//		n2(=0-nn)	文字列Aで区切られた区画の対象位置(省略化) 省略した場合は先頭区画を対象とする。個数を超える場合は最後尾が対象となる。
			textLimitReplace( $filename, 'DESC', $description );
			textLimitReplace( $filename, 'TITLE', $title );

			// %PROCESS(TARGET[,OPERATE1[,OPERATEn]])%	加工されたタイトルまたは概要を取得　各要素はCSVフォーマットで連結する
			//		TARGET				取得対象を選択
			//			TITLE			タイトル
			//			DESC			概要
			//		OPERATE				TARGETに各種加工を行なう。加工の順番・回数に制限無し
			//			$CUT$,A			文字列Aを削除 文字列Aは複数指定も可能
			//			$REPLACE$,A,B	文字列Aを文字列Bに置換
			//			$SPRIT$,A,n		文字列Aで分割した第n区画を取得する(n=0-nn)
			//			$LIMIT$,n		取得byte数を制限する。負数の場合は後方から数える。
			while(1){
				$csv_word = operateParse( $filename, 'PROCESS' );
				if( $csv_word !== FALSE ){
$process_log = '<<< '.$csv_word." >>>\n";
					$parts = str_getcsv( $csv_word );
					if( $parts !== FALSE ){
						// ソース取得
						switch( $parts[0] ){
							case '$TITLE$':
								$dest_sorce = trim($title);
								break;
							case '$DESC$':
								$dest_sorce = trim($description);
								break;
							default:
file_put_contents( '/tmp/debug.txt', $process_log."\n", FILE_APPEND );
								break 2;	// 不正書式
						}
						$delim = array_shift( $parts );
$process_log .= $delim.'::'.$dest_sorce."\n";
						// 加工コマンド
						while( count($parts) && $dest_sorce!=='' ){
							$sub_cmd = array_shift( $parts );
							if( count($parts) ){
$process_log .= $sub_cmd."\n";
								switch( $sub_cmd ){
									case '$REPLACE$':
										if( extraWordCheck( $parts[0] ) )
											break;
										$src_wd = array_shift( $parts );
$process_log .= '$src_wd:'.$src_wd."\n";
										if( count($parts) ){
											if( extraWordCheck( $parts[0] ) )
												break;
											$dst_wd     = array_shift( $parts );
$process_log .= '$dst_wd:'.$dst_wd."\n";
											$dest_sorce = str_replace( $src_wd, $dst_wd, $dest_sorce );
$process_log .= $dest_sorce."\n";
											break;
										}
file_put_contents( '/tmp/debug.txt', $process_log."\n", FILE_APPEND );
										break 2;
									case '$CUT$':
										$word_stk = array();
										do{
											if( extraWordCheck( $parts[0] ) ){
												if( count($word_stk) )
													break;
												else{
file_put_contents( '/tmp/debug.txt', $process_log."\n", FILE_APPEND );
													break 2;
												}
											}else
												$word_stk[] = array_shift( $parts );
										}while( count($parts) );
foreach( $word_stk as $pp ) $process_log .= $pp.'　';
										$dest_sorce = str_replace( $word_stk, '', $dest_sorce );
$process_log .= "\n".$dest_sorce."\n";
										break;
									case '$SPRIT$':
										if( extraWordCheck( $parts[0] ) ){
file_put_contents( '/tmp/debug.txt', $process_log."\n", FILE_APPEND );
											break 2;
										}
										$delim = array_shift( $parts );
$process_log .= '$delim::'.$delim."\n";
										if( count($parts) ){
											if( is_numeric($parts[0]) )
												$offset = (int)array_shift( $parts );
											else{
												if( extraWordCheck( $parts[0] ) )
													$offset = 0;
												else{
file_put_contents( '/tmp/debug.txt', $process_log."\n", FILE_APPEND );
													break 2;	// 不正書式
												}
											}
										}else
											$offset = 0;
$process_log .= '$offset::'.$offset."\n";
										$dest_sorce = fn_substr( $dest_sorce, 0, $delim, $offset );
$process_log .= $dest_sorce."\n";
										break;
									case '$LIMIT$':
										if( is_numeric($parts[0]) )
											$cp_len = (int)array_shift( $parts );
										else{
											if( extraWordCheck( $parts[0] ) )
												$cp_len = 0;
											else{
file_put_contents( '/tmp/debug.txt', $process_log."\n", FILE_APPEND );
												break 2;	// 不正書式
											}
										}
$process_log .= '$cp_len::'.$cp_len."\n";
										$dest_sorce = fn_substr( $dest_sorce, $cp_len );
$process_log .= $dest_sorce."\n";
										break;
									default:
file_put_contents( '/tmp/debug.txt', $process_log."\n", FILE_APPEND );
										break 2;
								}
							}else
								break;
						}
						$filename = str_replace( '%PROCESS('.$csv_word.')%', $dest_sorce, $filename );
					}
				}else
					break;
			}
			// %CUT(A)% ファイル名全体から文字列Aを削除 文字列Aは、CSVフォーマットで記述(複数指定も可能)
			$csv_word = operateParse( $filename, 'CUT' );
			if( $csv_word !== FALSE ){
				$stokname = str_replace( '%CUT('.$csv_word.')%', '', $filename );
				$cut_strs = str_getcsv( $csv_word );
				if( $cut_strs !== FALSE )
					$filename = str_replace( $cut_strs, '', $stokname );
			}
			// %REPLACE(A,B)% ファイル名全体から 文字列Aを文字列Bに置換 文字列はCSVフォーマットで記述
			while(1){
				$csv_word = operateParse( $filename, 'REPLACE' );
				if( $csv_word !== FALSE ){
					$stokname = str_replace( '%REPLACE('.$csv_word.')%', '', $filename );
					$parts    = str_getcsv( $csv_word );
					$filename = count($parts)===2 ? str_replace( $parts[0], $parts[1], $stokname ) : $stokname;
				}else
					break;
			}

			if( defined( 'KATAUNA' ) ){
				// しょぼかるからサブタイトル取得(しょぼかるのスケジュール未登録分用)
				// 注意:epgdumpの非公開関数でEPG番組名が"タイトル #nn「」"の形に正規化されているのを前提としているのでここを有効にするだけでは恩恵はまったくない
				//      またこの処理を予約する際に必ず動くような使い方をすると「しょぼいカレンダー」に迷惑なのでやらないように(これは一括処理から漏れたものの最後の足掻き処理です)
				if( $category_id==8 && ( strpos( $filename, '「」' )!==FALSE || strpos( $filename, ' 他」' )!==FALSE ) ){
					$title_piece = explode( ' #', $filename );		// タイトル分離
					$trans       = str_replace( ' ', '', $title_piece[0] );
					$search      = array(	'!','"','#','$','%','&',"'",'(',')','*','+',',','-','.','/',':',';','<','=','>','?','@','[',"\\",']','^','_','{','|','}','~',
											'×','÷','±','´','°','§','¨','¶','！','”','＃','＄','％','＆','’','（','）','＊','＋','，','－','．','／','：','；','＜',
											'＝','＞','？','＠','［','￥','］','＾','＿','｛','｜','｝','￣','ー','。','「','」','、','・','～','…','♪','★','☆','●','○',
											'■','□','▼','▽','〈','〉','《','》','〔','〕','≪','≫','【','】','『','』','◆','“','‐','→','←','↑','↓','†' );
					$norma       = strtoupper( str_replace( $search, '', $trans ) );
					if( ( $handle = fopen( INSTALL_PATH.'/settings/Title_base.csv', 'r+') ) !== FALSE ){
						do{
							// タイトルリスト1行読み込み
							if( ( $data = fgetcsv( $handle ) ) === FALSE ){
								// 該当タイトルをしょぼカレで検索
								$search_nm = $title_piece[0];
								while(1){
									$find_ps = file_get_contents( 'http://cal.syoboi.jp/find?sd=0&r=0&v=0&kw='.urlencode($search_nm) );		// エンコードは変わるかも
									if( $find_ps !== FALSE ){
										if( strpos( $find_ps, 'href="/tid/' ) !== FALSE ){
											$dust_trim = explode( '外部サイトの検索結果', $find_ps );
											$tl_list   = explode( 'href="/tid/', $dust_trim[0] );
											for( $loop=1; $loop<count($tl_list); $loop++ ){
												if( strpos( $tl_list[$loop], '">'.$search_nm.'</a>' ) !== FALSE ){
													list( $tid, ) = explode( '">', $tl_list[$loop] );
													$data = array( (int)$tid, 1, 1, -1, $title_piece[0], $norma, $trans, str_replace( '・', '', $trans ) );
													fputcsv( $handle, $data );
													break 2;
												}
											}
											break 2;
										}else{
											if( $search_nm === $trans )
												break 2;	// end
											$search_nm = $trans;
										}
									}else
										break 2;
								}
							}
							if( is_numeric( $data[0] ) && $data[0]!==0 ){
								switch( $data[1] ){
									case 1:		// 国内
									case 4:		// 特撮
									case 10:	// 国内放送終了
									case 7:		// OVA
									case 20:	// 児童
									case 21:	// 非視聴
									case 22:	// 海外
									case 23:	// SD非視聴
										$num = count( $data );
										for( $loop=4; $loop<$num; $loop++ ){
											if( $loop === 4 ){
												$official = str_replace( '^', '', $data[4] );
												$dte      = str_replace( ' ', '', $official );
											}else
												$dte = $data[$loop];
											if( strcmp( $trans, $dte ) == 0 ){
												// 異形タイトルを正式タイトルに修正
												if( $loop === 2 ){
													if( strcmp( $official, $title_piece[0] ) )
														$filename = str_replace( $title_piece[0], $official, $filename );
												}else
													$filename = str_replace( $dte, $official, $filename );
												// しょぼカレから全サブタイトル取得
												$st_list = file( 'http://cal.syoboi.jp/db.php?Command=TitleLookup&Fields=SubTitles&TID='.$data[0], FILE_IGNORE_NEW_LINES );
												if( $st_list !== FALSE ){
													$st_count = count( $st_list );
													if( strpos( $title_piece[1], '」#' ) !== FALSE )
														$sub_pieces = explode( '」#', $title_piece[1] );
													else
														$sub_pieces[0] = $title_piece[1];
													foreach( $sub_pieces as $sub_piece ){
														if( strpos( $sub_piece.'」', '「」' )!==FALSE || strpos( $sub_piece.'」', ' 他」' )!==FALSE ){
															$scount = (int)$sub_piece;							// 強引？
															if( $scount>=0 && $scount <= $st_count ){
																$num_cmp = sprintf( '%d*', $scount );
																if( $scount>0 && strpos( $st_list[$scount-1], $num_cmp )!= FALSE )
																	$sub_zero = 0;
																else
																	if( $scount<$st_count && strpos( $st_list[$scount], $num_cmp )!==FALSE )
																		$sub_zero = 1;
																	else
																		continue;
																if( $scount+$sub_zero === $st_count ){
																	list( $subsplit, $dust ) = explode( '</SubTitles>', $st_list[$scount+$sub_zero-1] );
																	list( , $subtitle )      = explode( $num_cmp, $subsplit );
																}else
																	list( , $subtitle ) = explode( $num_cmp, $st_list[$scount+$sub_zero-1] );
																$filename = str_replace( sprintf( '#%02d「」', $scount ), sprintf( '#%02d「%s」', $scount, $subtitle ), $filename );
															}
														}
													}
												}
												break 3;
											}
										}
										break;
									default:
										break;
								}
							}
						}while( !isset( $search_nm ) );
						fclose( $handle );
					}
				}
			}

			// あると面倒くさそうな文字を全部_に
//			$filename = preg_replace("/[ \.\/\*:<>\?\\|()\'\"&]/u","_", trim($filename) );
			
			// 全角に変換したい場合に使用
/*			$trans = array( '[' => '［',
							']' => '］',
							'/' => '／',
							'\'' => '’',
							'"' => '”',
							'\\' => '￥',
						);
			$filename = strtr( $filename, $trans );
*/
			// UTF-8に対応できない環境があるようなのでmb_ereg_replaceに戻す
//			$filename = mb_ereg_replace("[ \./\*:<>\?\\|()\'\"&]","_", trim($filename) );
			$filename = mb_ereg_replace( "[\\/\'\"]", '_', trim($filename) );

			// ディレクトリ付加
			$add_dir = $autorec && $keyword->directory!='' ? $keyword->directory.'/' : '';

			// 文字コード変換
			if( defined( 'FILESYSTEM_ENCODING' ) ) {
				$filename = mb_convert_encoding( $filename, FILESYSTEM_ENCODING, 'UTF-8' );
				$add_dir  = mb_convert_encoding( $add_dir, FILESYSTEM_ENCODING, 'UTF-8' );
			}

			// ファイル名長制限+ファイル名重複解消
			$fl_len     = strlen( $filename );
			$fl_len_lmt = 255 - strlen( $RECORD_MODE[$mode]['suffix'] );
			if( (boolean)$settings->use_thumbs )
				$fl_len_lmt -= 4;		// サムネール '.jpg'
			if( $fl_len > $fl_len_lmt ){
				$longname = $filename;
				$filename = mb_strncpy( $filename, $fl_len_lmt );
				if( preg_match( '/^(.*)\040(\#\d+)(「.*」)/', $longname, $matches ) ){
					$longcut = $matches[1].' '.$matches[2];
					if( strlen( $longcut ) > $fl_len_lmt )
						$longcut = mb_strncpy( $longcut, $fl_len_lmt-4 );
					$longcut .= '.txt';
					file_put_contents( $spool_path.'/'.$add_dir.$longcut, $matches[2].str_replace('」#', "」\n#", $matches[3] )."\n\n", FILE_APPEND );
				}else
					file_put_contents( $spool_path.'/longname.txt', $filename." <-\n".$longname."\n->\n", FILE_APPEND );
				$fl_len = strlen( $filename );
			}
			$files = scandir( $spool_path.'/'.$add_dir );
			if( $files !== FALSE )
				array_splice( $files, 0, 2 );
			else
				$files = array();
			$file_cnt = 0;
			$tmp_name = $filename;
			$sql_que  = 'WHERE path LIKE \''.DBRecord::sql_escape($add_dir.$tmp_name.$RECORD_MODE[$mode]['suffix']).'\'';
			while( in_array( $tmp_name.$RECORD_MODE[$mode]['suffix'], $files ) || DBRecord::countRecords( RESERVE_TBL, $sql_que )!==0 ){
				$file_cnt++;
				$len_dec = strlen( (string)$file_cnt );
				if( $fl_len > $fl_len_lmt-$len_dec ){
					$filename = mb_strncpy( $filename, $fl_len_lmt-$len_dec );
					$fl_len   = strlen( $filename );
				}
				$tmp_name = $filename.$file_cnt;
				$sql_que  = 'WHERE path LIKE \''.DBRecord::sql_escape($add_dir.$tmp_name.$RECORD_MODE[$mode]['suffix']).'\'';
			}
			$filename  = $tmp_name.$RECORD_MODE[$mode]['suffix'];
			$thumbname = $filename.'.jpg';

			// ファイル名生成終了

			// 予約レコード生成
			$rrec = new DBRecord( RESERVE_TBL );
			$rrec->channel_disc  = $crec_->channel_disc;
			$rrec->channel_id    = $crec_->id;
			$rrec->program_id    = $program_id;
			$rrec->type          = $crec_->type;
			$rrec->channel       = $crec_->channel;
			$rrec->title         = $title;
			$rrec->description   = $description;
			$rrec->category_id   = $category_id;
			$rrec->sub_genre     = $sub_genre;
			$rrec->starttime     = toDatetime( $start_time );
			$rrec->endtime       = toDatetime( $end_time );
			$rrec->path          = $add_dir.$filename;
			$rrec->autorec       = $autorec;
			$rrec->mode          = $mode;
			$rrec->tuner         = $tuner;
			$rrec->priority      = $priority;
			$rrec->overlap       = $overlap;
			$rrec->discontinuity = $discontinuity;
			$rrec->shortened     = $shortened;
			$rrec->reserve_disc  = md5( $crec_->channel_disc . toDatetime( $start_time ). toDatetime( $end_time ) );
			//
			$descriptor = array( 0 => array( 'pipe', 'r' ),
			                     1 => array( 'pipe', 'w' ),
			                     2 => array( 'pipe', 'w' ),
			);
			// AT発行準備
			$cmdline = $settings->at.' '.date('H:i m/d/Y', $at_start);
			$env = array( 'CHANNEL'    => $crec_->channel,
						  'DURATION'   => $duration,
						  'OUTPUT'     => $spool_path.'/'.$add_dir.$filename,
						  'TYPE'       => $crec_->type,
						  'TUNER'      => $tuner,
						  'MODE'       => $mode,
						  'TUNER_UNIT' => TUNER_UNIT1,
						  'THUMB'      => INSTALL_PATH.$settings->thumbs.'/'.$thumbname,
						  'FORMER'     => $settings->former_time,
						  'FFMPEG'     => $settings->ffmpeg,
						  'SID'        => $crec_->sid,
						  'EID'        => $eid,
						  'RESOLUTION' => $resolution,
						  'ASPECT'     => $aspect,
						  'AUDIO_TYPE' => $audio_type,
						  'BILINGUAL'  => $bilingual,
			);
			// ATで予約する
			$process = proc_open( $cmdline , $descriptor, $pipes, $spool_path, $env );
			if( !is_resource( $process ) ) {
				$rrec->delete();
				reclog( 'atの実行に失敗した模様', EPGREC_ERROR);
				throw new Exception('AT実行エラー');
			}
			fwrite($pipes[0], 'echo $$ >/tmp/tuner_'.$rrec->id."\n" );		//ATジョブのPID保存ファイルの作成
			if( $sleep_time ){
				if( $program_id && $sleep_time > $settings->rec_switch_time )
					fwrite($pipes[0], "echo 'temp' > './".$add_dir.'/tmp\' & sync & '.INSTALL_PATH.'/scoutEpg.php '.$rrec->id." &\n" );		//HDD spin-up + 単発EPG更新
				else
					fwrite($pipes[0], "echo 'temp' > './".$add_dir."/tmp' & sync &\n" );		//HDD spin-up
				fwrite($pipes[0], $settings->sleep.' '.$sleep_time."\n" );
			}

			if( !USE_DORECORD ){
				if( $smf_type === 'EX' ){
					$cmd_num = $EX_TUNERS_CHARA[$tuner]['reccmd'];
					$device  = $EX_TUNERS_CHARA[$tuner]['device']!=='' ? ' '.trim($EX_TUNERS_CHARA[$tuner]['device']) : '';
				}else{
					if( $tuner < TUNER_UNIT1 ){
						$cmd_num = PT1_CMD_NUM;
						$device  = '';
					}else{
						$cmd_num = $OTHER_TUNERS_CHARA[$smf_type][$tuner-TUNER_UNIT1]['reccmd'];
						$device  = $OTHER_TUNERS_CHARA[$smf_type][$tuner-TUNER_UNIT1]['device']!=='' ? ' '.trim($OTHER_TUNERS_CHARA[$smf_type][$tuner-TUNER_UNIT1]['device']) : '';
					}
				}
				$slc_cmd  = $rec_cmds[$cmd_num];
				$sid      = $mode==0 ? '' : ( $slc_cmd['sidEXT']!=='' ? ' --sid '.$slc_cmd['sidEXT'].','.$crec_->sid : ' --sid '.$crec_->sid );
				$falldely = $slc_cmd['falldely']>0 ? ' || sleep '.$slc_cmd['falldely'] : '';
				$cmd_ts   = $slc_cmd['cmd'].$slc_cmd['b25'].$device.$sid.' '.$crec_->channel.' '.$duration.' \''.$add_dir.$filename.'\' >/dev/null 2>&1'.$falldely;
				fwrite($pipes[0], $cmd_ts."\n" );
			}else
				fwrite($pipes[0], DO_RECORD.' '.$rrec->id."\n" );		//$rrec->id追加は録画キャンセルのためのおまじない
			fwrite($pipes[0], COMPLETE_CMD.' '.$rrec->id."\n" );
			if( $settings->use_thumbs == 1 ) {
				$gen_thumbnail = defined( 'GEN_THUMBNAIL' ) ? GEN_THUMBNAIL : INSTALL_PATH.'/gen-thumbnail.sh';
				fwrite($pipes[0], $gen_thumbnail."\n" );
			}
			fwrite($pipes[0], 'rm /tmp/tuner_'.$rrec->id."\n" );		//ATジョブのPID保存ファイルを削除
			fclose($pipes[0]);
			// 標準エラーを取る
			$rstring = stream_get_contents( $pipes[2]);
			
			fclose( $pipes[2] );
		    fclose( $pipes[1] );
			proc_close( $process );
			// job番号を取り出す
			$rarr = array();
			$tok = strtok( $rstring, " \n" );
			while( $tok !== false ) {
				array_push( $rarr, $tok );
				$tok = strtok( " \n" );
			}
			// OSを識別する(Linux、またはFreeBSD)
			//$job = php_uname('s') == 'FreeBSD' ? 'Job' : 'job';
			$job = PHP_OS == 'FreeBSD' ? 'Job' : 'job';
			$key = array_search( $job, $rarr );
			if( isset( $sem_id ) )
				while( sem_release( $sem_id ) === FALSE )
					usleep( 100 );
			if( $key !== false ) {
				if( is_numeric( $rarr[$key+1]) ) {
					$rrec->job = $rarr[$key+1];
					$rrec->update();
					$put_msg = '[予約ID:'.$rrec->id.' 登録] '.$rrec->channel_disc.'(T'.$rrec->tuner.'-'.$rrec->channel.') '.$rrec->starttime.' 『'.$title.'』';
					if( $autorec )
						$put_msg = autoid_button( $autorec ).htmlspecialchars( $put_msg );
					reclog( $put_msg );
					return $program_id.':'.$tuner.':'.$rrec->id;			// 成功
				}
			}
			// エラー
			$rrec->delete();
			reclog( 'ジョブNoの取得に失敗<br>/etc/at.denyに'.HTTPD_USER.'が登録されていたら'.HTTPD_USER.'を削除してください。', EPGREC_ERROR );
			throw new Exception( 'ジョブNoの取得に失敗' );
		}
		catch( Exception $e ) {
			if( $rrec != null ) {
				if( $rrec->id ) {
					// 予約を取り消す
					$rrec->delete();
				}
			}
			throw $e;
		}
	}

	// 取り消し
	public static function cancel( $reserve_id = 0, $program_id = 0, $db_clean = FALSE ) {
		global $rec_cmds,$OTHER_TUNERS_CHARA,$EX_TUNERS_CHARA;
		$settings = Settings::factory();
		$rec = null;
		try {
			$rev_obj = new DBRecord( RESERVE_TBL );
			if( $reserve_id ) {
				$prev_recs = $rev_obj->fetch_array( 'id', $reserve_id );
				$rec = $prev_recs[0];
				$ret = '0';
			}
			else if( $program_id ) {
				$prev_recs = $rev_obj->fetch_array( 'program_id', $program_id, 'complete=0 ORDER BY starttime ASC' );
				$rec = $prev_recs[0];
				$ret = (string)(count( $prev_recs ) - 1);
			}
			if( $rec == null ) {
				throw new Exception('IDの指定が無効です');
			}
			if( ! $rec['complete'] ){
				// 予約解除
				$rec_st = toTimestamp($rec['starttime']);
				$pad_tm = $rec_st%60 ? PADDING_TIME+60-$rec_st%60 : PADDING_TIME;
				$rec_at = $rec_st - $pad_tm;
				$rec_st -= $settings->former_time;
				$rec_ed = toTimestamp($rec['endtime']);
				$now_tm = time();
				if( $rec_at-2 <= $now_tm ){
					if( !$db_clean && $rec_st-2<=$now_tm ){
						// 実行中の予約解除
						if( $now_tm <= $rec_ed ){
							if( $rec_st >= $now_tm )
								sleep(3);
							//録画停止
							$ps = search_reccmd( $rec['id'] );
							if( $ps !== FALSE ){
								$wrt_set['autorec'] = ( $rec['autorec'] + 1 ) * -1;
								$rev_obj->force_update( $rec['id'], $wrt_set );
								$prev_tuner = $rec['tuner'];
								$smf_type = $rec['type']==='CS' ? 'BS' : $rec['type'];
								if( $smf_type === 'EX' )
									$cmd_num = $EX_TUNERS_CHARA[$prev_tuner]['reccmd'];
								else
									$cmd_num = $prev_tuner<TUNER_UNIT1 ? PT1_CMD_NUM : $OTHER_TUNERS_CHARA[$smf_type][$prev_tuner-TUNER_UNIT1]['reccmd'];
								if( $rec_cmds[$cmd_num]['cntrl'] ){
									// recpt1ctlで停止
									exec( RECPT1_CTL.' --pid '.$ps->pid.' --time 10 >/dev/null 2>&1' );
								}else{
									//コントローラの無いチューナへの汎用処理
									posix_kill( $ps->pid, 15 );		//録画停止
								}
								return $ret;
							}
						}else{
							//AT削除
							if( at_clean( $rec, $settings, TRUE ) === 2 ){
								// 別ユーザーでAT登録
								return $ret;
							}
						}
						//DB残留 DB削除へ
					}else{
						//sleep待機中の予約解除or録画停止を伴うDB削除
						if( !$db_clean && $rec_at>=$now_tm )
							sleep(3);
						$atpidfile = '/tmp/tuner_'.$rec['id'];
						$atjob_pid = (int)trim( file_get_contents( $atpidfile ) );
						$ps_output = shell_exec( PS_CMD );
						$rarr      = explode( "\n", $ps_output );
						$my_pid    = $db_clean ? 0 : posix_getpid();
						$stop_stk  = killtree( $rarr, $atjob_pid, FALSE, $my_pid );
						unlink( $atpidfile );
						if( $stop_stk ){
							reclog( '[予約ID:'.$rec['id'].' 削除] '.$rec['channel_disc'].'(T'.$rec['tuner'].'-'.$rec['channel'].') '.$rec['starttime'].' 『'.$rec['title'].'』' );
							$rev_obj->force_delete( $rec['id'] );
							return $ret;
						}
						throw new Exception( '予約キャンセルに失敗した' );
					}
				}else{
					//AT削除
					if( at_clean( $rec, $settings, TRUE ) === 2 ){
						// 別ユーザーでAT登録
						return $ret;
					}
				}
			}
			$rev_obj->force_delete( $rec['id'] );
			return $ret;
		}
		catch( Exception $e ) {
			reclog('Reservation::cancel 予約キャンセルでDB接続またはアクセスに失敗した模様 $reserve_id:'.$reserve_id.' $program_id:'.$program_id, EPGREC_ERROR );
			throw $e;
		}
	}
}
?>
