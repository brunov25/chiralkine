<? require('connect.php');
// (c) GPL 2013 by Eric Harris-Braun, Adam Soltys, Bruno Vernier, Michael Linton, Martin and Frances Hay 
#TODO: authentication check

$query = "SELECT * from v_transactions where flags!='red' ORDER by tid,id";
$result = $db->query($query);
if (!$result) {echo "nothing to process";exit;}  

$i = 0;
$transactions = array(); 
$matrix = array();
while ($row = $result->fetch_assoc()) {
  $i++; 
  $transactions[$i]=$row; 
  $matrix[$row['laccount']][$i] = array('Ldebug'=>'1');
  $matrix[$row['raccount']][$i] = array('Rdebug'=>'2');
}

echo "<h3>Chiralkine Calculations</h3><table border>";
$result = $db->query("DELETE FROM v_chiralkines;");

$i=1;
foreach ($transactions as $id=>$transaction) {
  // STEP 1: calculate level 1 chiralkines - FIRST PASS ===============================================================
  $id1 = $i++; //first (odd) chiralkine record
  $id2 = $i++; //second (even) chiralkine record
  $debug = ''; 
  $tid = $transaction['tid'];
  $laccount = $transaction['laccount'];
  $raccount = $transaction['raccount'];
  $pair = "$laccount,$raccount";
  // strangely it looks like "tid" does not always work, but tid2 does
  $matrix[$laccount][$id]['tid']      = $matrix[$laccount][$id]['tid']      = "$tid";
  $matrix[$laccount][$id]['tid2']     = $matrix[$raccount][$id]['tid2']     = "$tid";
  $matrix[$laccount][$id]['laccount'] = $matrix[$raccount][$id]['laccount'] = "$laccount";
  $matrix[$laccount][$id]['raccount'] = $matrix[$raccount][$id]['raccount'] = "$raccount";
  $matrix[$laccount][$id]['pair']     = $matrix[$raccount][$id]['pair']     = "$pair";
  $amount   = $matrix[$laccount][$id]['amount'] = $transaction['amount'];
  $tcreated = $transaction['tcreated'];
  $currency = $transaction['currency'];
  $description = $transaction['description'];
  $lamount   = $matrix[$laccount][$id]['lamount'] = $transaction['lamount'] = -1*$amount; //left money is an obligation
  $ramount   = $matrix[$raccount][$id]['ramount'] = $transaction['ramount'] = $amount; //right money is capacity to spend
  $balance1  = previous('balance' , $id, $laccount, $lamount, 0); // ODD chiralkine record
  $balance2  = previous('balance' , $id, $raccount, $ramount, 0); // EVEN chiralkine record
  $volume1   = previous('volume'  , $id, $laccount, $amount, 0); 
 $volume2   = previous('volume'  , $id, $raccount, $amount, 0);
  $lbalance1 = $prelb1 = $matrix[$laccount][$id]['lbalance'] = previous('lbalance', $id, $laccount, abs($lamount), 0); //always positive
  $rbalance1 = $matrix[$laccount][$id]['rbalance'] = previous('rbalance', $id, $laccount, 0, 0, 0); //no change
  $lbalance2 = $matrix[$raccount][$id]['lbalance'] = previous('lbalance', $id, $raccount, 0, 0, 0); //no change
  $rbalance2 = $prerb2 = $matrix[$raccount][$id]['rbalance'] = previous('rbalance', $id, $raccount, abs($ramount), 0);
  $lbalance1priorpruning = $prelb1 - abs($lamount) ; //we need to keep track of the some prior balances
  $rbalance2priorpruning = $rbalance2 - abs($ramount) ;

  $contract = 0;
  $contract = $matrix[$laccount][$id]['contract'] = $matrix[$raccount][$id]['contract'] = 
              max(0, abs($amount)-abs($rbalance1), abs($amount)-abs($lbalance2));
  $prune = abs($amount)-abs($contract); // amount which we can prune right away
  $rbalance1post = $matrix[$laccount][$id]['rbalance'] = $rbalance1 - $prune ;
  $lbalance2post = $matrix[$raccount][$id]['lbalance'] = $lbalance2 - $prune ;

  $lcontract1 = $prelc1 = $matrix[$laccount][$id]['lcontract'] = previous('lcontract', $id, $laccount, 0, 0, 0); //no change
  $rcontract1 = $matrix[$laccount][$id]['rcontract'] = previous('rcontract', $id, $laccount, $contract, 0);
  $lcontract2 = $matrix[$raccount][$id]['lcontract'] = previous('lcontract', $id, $raccount, $contract, 0);
  $rcontract2 = $matrix[$raccount][$id]['rcontract'] = previous('rcontract', $id, $raccount, 0, 0, 0); //no change
  $rcontract1priorpruning = $rcontract1 - $contract ;
  $lcontract2priorpruning = $lcontract2 - $contract ;
  $prune = $prune?"<b>$prune</b>":'0';
  //level 1 2 3 stuff: look for stuff to redeem
  // redeemable loop ... keep going until all possibilities have been exhausted
  // level 1 transaction = the current transaction (outermost level)
  // level 2 transaction = the first matching PREVIOUS transaction involving the right (with) account in level 1
  // level 3 transaction = the first matching PREVIOUS transaction involving level2's right (with) account
  // find min (level1_lbalance, level3_rbalance, level2_contract_remaining)
  $redeemables1 = '';
  $redeemables2 = '';
  ksort($matrix[$laccount]);
  ksort($matrix[$raccount]);
  $query_insert1 = "INSERT INTO v_chiralkines (tamount,tid,pair,account,balance,volume,lbalance,rbalance,contract,lcontract,rcontract) 
            VALUES ('$lamount','$tid','$pair','$laccount',$balance1,$volume1,$lbalance1,$rbalance1post,$contract,$lcontract1,$rcontract1);";
  $query_insert2 = "INSERT INTO v_chiralkines (tamount,tid,pair,account,balance,volume,lbalance,rbalance,contract,lcontract,rcontract) 
            VALUES ('$ramount','$tid','$pair','$raccount',$balance2,$volume2,$lbalance2post,$rbalance2,$contract,$lcontract2,$rcontract2);";
  $result = $db->query($query_insert1);
  $result = $db->query($query_insert2);

  foreach ($matrix[$laccount] as $key2=>$value2){ // STEP 2 - BUYER-side level2 REDEMPTION LOOP (start with oldest, until exhausted) ======
    if (($key2 >=$id) OR ($lcontract1<=0) OR ($lbalance1<=0)) {$debug .= ""; break;} //level 1 check
    if (!isset($value2['tid2'])) {continue;}
    $level2_tid       = $value2['tid2'];
    $debug .="<br>level1: $tid ; level2 - buyer: $level2_tid; ";
    $level2_pair      = $value2['pair'];
    $level2_contract  = $value2['contract'];
    $level2_lcontract = $value2['lcontract'];
    $level2_rcontract = $value2['rcontract'];
    $level2_lbalance  = $value2['lbalance'];
    $level2_rbalance  = $value2['rbalance'];
    $level2_balance   = $value2['balance'];
    $level2_volume    = $value2['volume'];
    $level2_laccount  = $value2['laccount'];
    $level2_raccount  = $value2['raccount'];
    $level2_amount    = $value2['amount'];
    if ($level2_contract AND ($level2_laccount != $laccount) AND $level2_lcontract) { //ODD
      $level3_rbalance = latest('rbalance',$id,$level2_laccount,0,0,0);
      $redeemables1 .= "<br>$level2_laccount ${level2_tid}:($level2_pair) has contract R$level2_contract 
                        <br>level3  RB = R$level3_rbalance";
      $redeem1 = min($lbalance1, $level3_rbalance, $level2_lcontract);
      if ($redeem1) {
        $level3_rbalance = latest('rbalance',$id,$level2_laccount,-1*$redeem1,0,1);
        $level3_rcontract = latest('rcontract',$id,$level2_laccount,-1*$redeem1,0,1);
        $matrix[$laccount][$key2]['contract'] = $matric[$laccount][$key2]['contract'] - $redeem1;
        $redeemables1 .= " - redeem $redeem1 <!--min of $lbalance1, $level3_rbalance, $level2_lcontract -->= <b>R$level3_rbalance</b>
                          RC: $level3_rcontract";
        $postlb1 = $matrix[$laccount][$id]['lbalance'] = $lbalance1 - $redeem1; 
        $postlc1 = $matrix[$laccount][$id]['lcontract'] = $lcontract1 - $redeem1; 
        $level3_tid1L = previous('tid2',$id+1,$level2_raccount,0,0,0);
        $debug .= " level3: $level3_tid1L 1L";
        $level3_balance = latest('balance',$id,$level2_laccount,0,0,0);
        $level3_volume = latest('volume',$id,$level2_laccount,0,0,0);
        $level3_lcontract = latest('lcontract',$id,$level2_laccount,0,0,0);
        #$level3_rcontract = latest('rcontract',$id,$level2_laccount,0,0,0);
        $level3_lbalance = latest('lbalance',$id,$level2_laccount,0,0,0);
        $level3_lamount = latest('lamount',$id,$level2_laccount,0,0,0);
        #$level3_rbalance = latest('rbalance',$id,$level2_laccount,0,0,0);
        $cflags = "$tid => ${level2_tid}\nbal(L$level2_lbalance, R$level2_rbalance) con(L$level2_lcontract, R$level2_rcontract)";
        $query_insert1 = "INSERT INTO v_chiralkines 
        (tamount,tid,pair,account,balance,volume,lbalance,rbalance,contract,lcontract,rcontract,cflags) 
        VALUES ('$level3_lamount','${level2_tid}-red1L','$level2_pair','$level2_laccount','$level3_balance','$level3_volume',
                '$level3_lbalance','$level3_rbalance','-$redeem1','$level3_lcontract','$level3_rcontract','1L $cflags');"; // 1L
        #$debug .= "<br>$query_insert1";
#        $debug .="<br>".print_r($matrix[$laccount],TRUE);
        $result = $db->query($query_insert1);
        $level3_tid1R = previous('tid2',$id+1,$level2_raccount,0,0,0);
        $debug .= " level3: $level3_tid1R 1R";
        $level3_balance = previous('balance',$id+1,$level2_raccount,0,0,0);
        $level3_volume = previous('volume',$id+1,$level2_raccount,0,0,0);
        $level3_ramount = previous('ramount',$id+1,$level2_raccount,0,0,0);
        $level3_lbalance = previous('lbalance',$id+1,$level2_raccount,0,0,0);
        $level3_rbalance = previous('rbalance',$id+1,$level2_raccount,0,0,0);
        $level3_lcontract = previous('lcontract',$id+1,$level2_raccount,0,0,0);
        $level3_rcontract = previous('rcontract',$id+1,$level2_raccount,0,0,0);
        $cflags = "$tid => ${level2_tid}\nbal(L$level3_lbalance, R$level3_lbalance) con(L$level3_lcontract, R$level3_rcontract)";
        $query_insert1 = "INSERT INTO v_chiralkines 
        (tamount,tid,pair,account,balance,volume,lbalance,rbalance,contract,lcontract,rcontract,cflags) 
        VALUES ('$level3_ramount','${level2_tid}-red1R','$level2_pair','$level2_raccount','$level3_balance','$level3_volume',
                '$level3_lbalance','$level3_rbalance','-$redeem1','$level3_lcontract','$level3_rcontract','1R $cflags');"; // 1R
        $result = $db->query($query_insert1);
      }
    }
    // STEP 3 : calculate STILL_TO_BE_REDEEMED and decide if loop back to second step ==============================================
  }
  ksort($matrix[$raccount]);
  #echo "<hr><pre>";print_r($matrix);echo "</pre>";
  foreach ($matrix[$raccount] as $key2=>$value2){ // STEP 4 - SELLER-side level2 REDEMPTION loop ==================================== 
    if (($key2 >=$id) OR ($rcontract2<=0) OR ($rbalance2<=0)) {$debug .= ""; break;} //level 1 check
    $level2_tid       = $value2['tid2'];
    $level2_pair      = $value2['pair'];
    $debug .="<br>level1: $tid ; level2 - seller: $level2_tid ;";
    $level2_contract  = $value2['contract'];
    $level2_lcontract = $value2['lcontract'];
    $level2_rcontract = $value2['rcontract'];
    $level2_lbalance  = $value2['lbalance'];
    $level2_rbalance  = $value2['rbalance'];
    $level2_laccount  = $value2['laccount'];
    $level2_raccount  = $value2['raccount'];
    if ($level2_contract AND ($level2_raccount != $raccount) AND $level2_rcontract) { //EVEN
      $level3_lbalance = previous('lbalance',$id+1,$level2_raccount,0,0,0); //note the id+1 to look in odd side of current transaction
      $redeemables2 .= "<br>$level2_raccount ${level2_tid}:($level2_pair) has contract L$level2_contract 
                        <br>level3 LB = L$level3_lbalance";
      $redeem2 = min($rbalance2, $level3_lbalance, $level2_rcontract);
      if ($redeem2) {
        $level3_tid2L = previous('tid2',$id+1,$level2_raccount,0,0,0);
        $debug .= " level3: $level3_tid2L 2L";
        $level3_volume = previous('volume',$id+1,$level2_raccount,0,0,0);
        $level3_balance = previous('balance',$id+1,$level2_raccount,0,0,0);
        $level3_lbalance = previous('lbalance',$id+1,$level2_raccount,-1*$redeem2,0,1);
        $level3_rbalance = previous('rbalance',$id+1,$level2_raccount,0,0,0);
        $level3_lcontract = previous('lcontract',$id+1,$level2_raccount,-1*$redeem2,0,1);
        $level3_rcontract = previous('rcontract',$id+1,$level2_raccount,0,0,0);
        $matrix[$raccount][$key2]['contract'] = $matrix[$raccount][$key2]['contract'] - $redeem2;
        $redeemables2 .= " - redeem $redeem2 <!--min of $rbalance2, $level3_lbalance, $level2_rcontract--> = <b>L$level3_lbalance</b>
                          LC: $level3_lcontract";
        $postrb2 = $matrix[$raccount][$id]['rbalance'] = $rbalance2 - $redeem2;  //level1 
        $postrc2 = $matrix[$raccount][$id]['rcontract'] = $rcontract2 - $redeem2;  //level1
        $level3_ramount = previous('ramount',$id+1,$level2_raccount,0,0,0);
        $cflags = "$tid => ${level2_tid}\nbal(L$level3_lbalance, R$level3_lbalance) con(L$level3_lcontract, R$level3_rcontract)";
        $query_insert2 = "INSERT INTO v_chiralkines 
        (tamount,tid,pair,account,balance,volume,lbalance,rbalance,contract,lcontract,rcontract,cflags) 
        VALUES ('$level3_ramount','${level2_tid}-red2R','$level2_pair','$level2_raccount','$level3_balance','$level3_volume',
                '$level3_lbalance','$level3_rbalance','-$redeem2','$level3_lcontract','$level3_rcontract','2L $cflags');"; // red2R
#  $debug .= "<br>matrix value =". $matrix[$raccount][$id]['balance']."while level3_balance=$level3_balance"; 
 #      $debug .="<br>".print_r($matrix[$raccount][$id],TRUE);
        $result = $db->query($query_insert2);
        $level3_tid2R = previous('tid2',$id+1,$level2_laccount,0,0,0);
        $debug .= " level3: $level3_tid2R 2R";
        $level3_volume = previous('volume',$id+1,$level2_laccount,0,0,0);
        $level3_balance = previous('balance',$id+1,$level2_laccount,0,0,0);
        $level3_lbalance = previous('lbalance',$id+1,$level2_laccount,0,0,0);
        $level3_rbalance = previous('rbalance',$id+1,$level2_laccount,0,0,0);
        $level3_lcontract = previous('lcontract',$id+1,$level2_laccount,0,0,0);
        $level3_rcontract = previous('rcontract',$id+1,$level2_laccount,0,0,0);
        $cflags = "$tid => ${level2_tid}\nbal(L$level2_lbalance, R$level2_lbalance) con(L$level2_lcontract, R$level2_rcontract)";
        $query_insert2 = "INSERT INTO v_chiralkines 
        (tamount,tid,pair,account,balance,volume,lbalance,rbalance,contract,lcontract,rcontract,cflags) 
        VALUES ('$level3_lamount','${level2_tid}-red2L','$level2_pair','$level2_laccount','$level3_balance','$level3_volume',
                '$level3_lbalance','$level3_rbalance','-$redeem2','$level3_lcontract','$level3_rcontract','2R $cflags');"; // red2L
#        $debug .= "<br>TID2L$level2_raccount: $level3_tid2L  TID2R$level2_laccount: $level3_tid2R debug: level2_laccount= $level2_laccount id=$id+1";
    #$debug .= "<br>$query_insert1<br>DEBUG level3_lbalance = $level3_rbalance debug: level2_laccount= $level2_laccount id=$id+1";
        $result = $db->query($query_insert2);
      }
    }
    // STEP 5: calculate STILL_TO_BE_REDEEMED (Seller-side) and decide if loop back to fourth step ====================================
  }
  $redeemables1 = $redeemables1?$redeemables1:'nothing to redeem';
  $redeemables2 = $redeemables2?$redeemables2:'nothing to redeem';
  //rating of players + overall metrics

  $printtransaction = "<tr><td><font size=+1><b>$tid</b></font></td><td colspan=7><font size=+2>($pair) exchanged <b>$currency$amount</b> 
                            for $description</font> on $tcreated</td></tr>";

  $prune1 = $prune?"<br>- pruning R<b>$prune</b> => (L$lbalance1, R$rbalance1post)":'';
  $prune2 = $prune?"<br>- pruning L<b>$prune</b> => (L$lbalance2post, R$rbalance2)":'';
  $postredeem1 = $redeem1?"<br>- redeem L<b>$redeem1</b> => (L$postlb1, R$rbalance1post)":'';
  $postredeem2 = $redeem2?"<br>- redeem R<b>$redeem2</b> => (L$lbalance2post, R$postrb2)":'';
  $postcontract1 = $redeem1?"<br>- redeem L<b>$redeem1</b> => (L$postlc1, R$rcontract1)":'';
  $postcontract2 = $redeem2?"<br>- redeem R<b>$redeem2</b> => (L$lcontract2, R$postrc2)":'';
  echo "$printtransaction 
        <tr bgcolor=bisque><td>$id1</td>
        <td> <b>$laccount</b></td><td><b>$lamount</b></td>
        <td>1d: balance: $balance1; volume: $volume1
        <br>2d: (L$lbalance1priorpruning, R$rbalance1) => (L$lbalance1, R$rbalance1) $prune1 $postredeem1</td>
        <td> contract: R<b>$contract</b>
        <br> (L$lcontract1, R$rcontract1priorpruning) => (L$lcontract1, R$rcontract1) $postcontract1</td>
        <td> Redeemable in $laccount ecosystem:<br><font size=-1>$redeemables1</font></td>
        </tr> <tr bgcolor=#FFFFCC><td>$id2</td>
        <td> <b>$raccount</b></td><td><b>$ramount</b></td>
        <td>1d: balance: $balance2; volume: $volume2 
        <br>2d: (L$lbalance2, R$rbalance2priorpruning) => (L$lbalance2, R$rbalance2) $prune2 $postredeem2</td>
        <td> contract: L<b>$contract</b>
        <br> (L$lcontract2priorpruning, R$rcontract2) => (L$lcontract2, R$rcontract2) $postcontract2</td>
        <td>Redeemable in $raccount ecosystem::<br><font size=-1>$redeemables2</font></td>  
        </tr> <tr bgcolor=pink><td colspan=8><font size=-1>$debug</font></td></tr>
        </tr>";
  $rbalance1post =$lbalance1 =$lbalance2post =$lrbalance1post =$postlb1 =$postlc1 =$rcontract2 =$postrc2 = 0 ;
}
echo "</table><pre>$query_insert</pre>";

// THE END. function definitions below:
function previous_sql ($field, $id, $acc, $amount, $dg, $update=1) {
  global $matrix, $debug;
  $number = '';
  $query = "(SELECT COALESCE((SELECT $field FROM v_chiralkines
             WHERE laccount = '$acc'
             AND tid <= $id                                                                                                    
             AND id <  $record_id                                                                                                                        AND flags != 'red'                                                                                                                          ORDER BY id DESC LIMIT 1)                                                                                                                   ,0)); ";
  $number = $db->query($query);
  $debug .= "<br>previous_sql $field $id $acc = $number";
  return $number;  
}
function latest ($field, $id, $acc, $amount, $dg, $update=1) {
  return previous($field, $id+1, $acc, $amount, $dg, $update=1);
}
function previous ($field, $id, $acc, $amount, $dg, $update=1) {
  global $matrix, $debug;
  $number = '';
  for ($i = $id-1; $i >= 0; $i--) {
    if (!isset($matrix[$acc][$i]['tid2'])) {continue;}
    $number = $matrix[$acc][$i][$field] ;
#    if ($field=='tid' OR $field=='tid2') {$debug .="...$i:$number...";}
    if ("$number"!='') { //must be in quotes otherwise will not process the number zero
      $debug1 = $dg?"<br>previous $field for $acc: $number + $amount = ":'';
      break;
    }
  }
  $number2 = $number?$number+$amount:$amount;
  $debug .=$dg?$debug1." $acc:$id:$field=$number2; ":'';
  if ($update) {$matrix[$acc][$id][$field] = $number2 ;}
  return $number2;
};

echo "<style>b.t {background-color: cyan} b.p {background-color: pink} b {background-color:yellow;}</style>";
?>
