<?php

namespace App\Models\System;

include(ROOT_DIR.'/app/controllers/mailchimp-api-master/src/MailChimp.php');

use \DrewM\MailChimp\MailChimp;

/**
 * News class
 */
class Mailer
{
    protected $apiKey = null;
    
    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;
    }
    
    function sendRegistrationEmail($email, $name, $login, $password) {
      
      ob_start();
          
      /*echo $email, '<br>';
      echo $name, '<br>';
      echo $login, '<br>';
      echo $password, '<br>';*/
          
      $MailChimp = new MailChimp($this->apiKey);
      $listId = '**********';
      $segmentId ='********';
      
      $result = $MailChimp->post("lists/$listId/members", [      
      				'email_address' => $email,
      				'status'        => 'subscribed',
      				'merge_fields' => [      					
                      'FNAME' => $name,
                      'LOGIN' => $login,
                      'PASSWORD' => $password
      				]
      			]);
      echo '<pre>'; print_r($result);
    
      if (!empty($result['status']) && $result['status'] == '400' && $result['title'] == 'Member Exists') {
        $subscriberHash = MailChimp::subscriberHash($email);
  
    	$result = $MailChimp->patch("lists/$listId/members/$subscriberHash", [
    									'merge_fields' => [      								
    										'FNAME' => $name,
                                          'LOGIN' => $login,
                                          'PASSWORD' => $password
    									]
    							]);
         echo '<pre>'; print_r($result);
      }      
    
      sleep(1);
      
      $result = $MailChimp->post("lists/$listId/segments/$segmentId/members", [
      				 'email_address' => $email
      ]);
      echo '<pre>'; print_r($result);
      
      $debug = ob_get_contents();
      ob_end_clean();
      
      //echo $debug;
      
      /*if ($MailChimp->success()) {
      	print_r($result);
      } else {
      	echo $MailChimp->getLastError();
      }
      */
      
      return $debug;
         
    }
    
//API update 02.09.2020 - "fix: Password recovery mails from mailchimp.com works only 1 time for each user" - BEGIN//
function sendPasswordRecoveryLinkEmail($email, $name, $link)
  {
  ob_start();
  //echo $email, '<br>'; echo $name, '<br>'; echo $link, '<br>';
  $MailChimp = new MailChimp($this->apiKey);
  $listId = '**********'; //Audience
  $campaignId = '**********'; //GNV_API - Password Recovery Link
  $subscriberHash = MailChimp::subscriberHash($email);

  //1.Update user from database to mailchimp list
  $result = $MailChimp->put("lists/$listId/members/$subscriberHash",
    [
    'email_address' => $email,
    'status' => 'subscribed',
    'merge_fields' => [
      'FNAME' => $name,
      'LOGIN' => $email,
      'RESETPASS' => $link,
      ],
    ]
  ); //echo '<pre>'; print_r($result); //Tester
  $searchTag = array_search($subscriberHash, array_column($result['tags'], 'name'));

  $segmentId = $result['tags']["$searchTag"]['id'];
  $prev_prl = $result['merge_fields']['PRL_ID'];
  $lastchanged = $result['merge_fields']['GNV_TS'];

//Antispam delay by new campaign unix time - 5s
if($lastchanged && time() - $lastchanged < 5):
  ob_end_clean();
  return 'Error 429';

else:
  //2.1.Delete prev campaign
  if($prev_prl) {$result = $MailChimp->delete("campaigns/$prev_prl");}

  //2.2.Create unique segment(tag)
  if(!$segmentId)
  {
  $result = $MailChimp->post("lists/$listId/segments", ['name' => "$subscriberHash", 'static_segment' => [$email],]);
  $segmentId = $result['id'];
  time_nanosleep(0, 200000);
  }

  //3.1.Add user to segment(tag)
  $result = $MailChimp->post("lists/$listId/segments/$segmentId/members", ['email_address' => $email]);

  //3.2.Replicate campaign GNV_API - Pass Recovery Link
  $result = $MailChimp->post("campaigns/$campaignId/actions/replicate");
  $new_prl = $result['id']; //Get new campaign id from Replicate response
  time_nanosleep(0, 200000);

  //4.Update(set) Campaign recipient
  $result = $MailChimp->patch("campaigns/$new_prl",
    [
    'recipients' => ['segment_opts' => ['match'=>'all', 'saved_segment_id' => (int)$segmentId, ], "$listId"],
    ]
  );
  time_nanosleep(0, 200000);

  //5.Add new campaign id to user prl_id field
  $result = $MailChimp->patch("lists/$listId/members/$subscriberHash",
    [
    'merge_fields' => [
      'PRL_ID' => "$new_prl",
      'GNV_TS' => time(),
      ],
    ]
  );

  //6.Send new campaign
  $result = $MailChimp->post("campaigns/$new_prl/actions/send");
endif;

  $debug = ob_get_contents();
  ob_end_clean();
  //echo $debug; if($MailChimp->success()) {print_r($result);} else {echo $MailChimp->getLastError();}
  return $debug;
  }

function sendPasswordRecoveryNewPasswordEmail($email, $name, $password)
  {
  ob_start();
  //echo $email, '<br>'; echo $name, '<br>'; echo $link, '<br>';
  $MailChimp = new MailChimp($this->apiKey);
  $listId = '4224516609'; //Audience
  $campaignId = '3dd7f83137'; //GNV_API - Send New Password
  $subscriberHash = MailChimp::subscriberHash($email);

  //1.Update user from database to mailchimp list
  $result = $MailChimp->put("lists/$listId/members/$subscriberHash",
    [
    'email_address' => $email,
    'status' => 'subscribed',
    'merge_fields' => [
      'FNAME' => $name,
      'LOGIN' => $email,
      'PASSWORD' => $password,
      ], 
    ]
  ); //echo '<pre>'; print_r($result); //Tester
  $searchTag = array_search($subscriberHash, array_column($result['tags'], 'name'));

  $segmentId = $result['tags']["$searchTag"]['id'];
  $prev_prl = $result['merge_fields']['PRL_ID'];

  $prev_snp = $result['merge_fields']['SNP_ID'];

  $lastchanged = $result['merge_fields']['GNV_TS']; // Новый таймстемп????????????

//Antispam delay by new campaign unix time - 3s
if($lastchanged && time() - $lastchanged < 3):
  ob_end_clean();
  return 'Error 429';

else:
  //2.1.Delete prev prl
  if($prev_prl) {$result = $MailChimp->delete("campaigns/$prev_prl");echo '<pre>'; print_r($result);}

  //2.2.Delete prev snp
  if($prev_prl) {$result = $MailChimp->delete("campaigns/$prev_snp");echo '<pre>'; print_r($result);}

  //2.3.Check unique segment(tag)
  if(!$segmentId)
  { return 'Error';
  /*
  $result = $MailChimp->post("lists/$listId/segments", ['name' => "$subscriberHash", 'static_segment' => [$email],]);
  $segmentId = $result['id'];
  time_nanosleep(0, 200000);
  */
  }

  //3.1.Add user to segment(tag)
  $result = $MailChimp->post("lists/$listId/segments/$segmentId/members", ['email_address' => $email]);

  //3.2.Replicate campaign GNV_API - Send New Password
  $result = $MailChimp->post("campaigns/$campaignId/actions/replicate");
  $new_snp = $result['id']; //Get new campaign id from Replicate response
  time_nanosleep(0, 200000);

  //4.Update(set) Campaign recipient
  $result = $MailChimp->patch("campaigns/$new_snp",
    [
    'recipients' => ['segment_opts' => ['match'=>'all', 'saved_segment_id' => (int)$segmentId, ], "$listId"],
    ]
  );

  time_nanosleep(0, 200000);

  //5.Add new campaign id to user snp_id field
  $result = $MailChimp->patch("lists/$listId/members/$subscriberHash",
    [
    'merge_fields' => [
      'SNP_ID' => "$new_snp",
      'GNV_TS' => time(),
      ],
    ]
  );

  //6.Send new campaign
  $result = $MailChimp->post("campaigns/$new_snp/actions/send");
endif;

  $debug = ob_get_contents();
  ob_end_clean();
  //echo $debug; if($MailChimp->success()) {print_r($result);} else {echo $MailChimp->getLastError();}
  return $debug;
  }
//API update 02.09.2020 - END//
}