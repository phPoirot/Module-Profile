<?php
namespace Module\Profile\Actions\Helpers;

use Module\HttpFoundation\Actions\Url;
use Module\Profile\Interfaces\Model\Repo\iRepoProfiles;
use Module\Profile\Model\Entity\EntityProfile;
use Poirot\TenderBinClient\FactoryMediaObject;


class RetrieveProfiles
{
    /** @var iRepoProfiles */
    protected $repoProfiles;


    /**
     * Construct
     *
     * @param iRepoProfiles $repoProfiles @IoC /module/profile/services/repository/Profiles
     */
    function __construct(iRepoProfiles $repoProfiles)
    {
        $this->repoProfiles = $repoProfiles;
    }


    /**
     * Retrieve Profiles For Given List Of Users By UID
     *
     * @param array  $userIds
     * @param string $mode    basic | full
     *
     * @return array
     */
    function __invoke(array $userIds, $mode = 'basic')
    {
        if (empty($userIds))
            // No Id(s) Given.
            return [];

        ## Normalize User Ids
        #
        foreach ($userIds as $i => $id)
            if (! is_string($id) )
                $userIds[$i] = (string) $id;


        # Retrieve User ID From OAuth
        #
        $oauthInfos = $nameFromOAuthServer = \Poirot\Std\reTry(function () use ($userIds) {
            $infos = \Module\OAuth2Client\Services::OAuthFederate()
                ->listAccountsInfoByUIDs($userIds);

            return $infos;
        });

        /*
         * [
             [598ee6c3110f3900154718b5] => [
               [user] => [
                 [uid] => 598ee6c3110f3900154718b5
                 [fullname] => Payam Naderi
                 [username] => pnaderi
                 [email] => naderi.payam@gmail.com
                 [mobile] => [
                   [country_code] => +98
                   [number] => 9386343994
                 ]
                 [meta] => [
                   [client] => test@default.axGEceVCtGqZAdW3rc34sqbvTASSTZxD
                 ]
                 ..
               [is_valid] =>
               [is_valid_more] => [
                    [username] => 1
                    [email] =>
                    [mobile] => 1
               ]
               ..
         */
        $oauthUsers = $oauthInfos['items'];


        # Retrieve Profiles
        #
        $crsr = $this->repoProfiles->findAllByUIDs( array_keys($oauthUsers) );

        // Create map of uid => entity; used on build response
        $profiles = [];
        /** @var EntityProfile $entity */
        foreach ($crsr as $entity)
            $profiles[(string)$entity->getUid()] = $entity;


        # Build Response
        #
        $r = [];
        foreach ($userIds as $uid) {
            if (! isset($oauthUsers[$uid]))
                continue;

            $oauthInfo = $oauthUsers[$uid];
            $entity    = @$profiles[ $uid ];

            $r[$uid] = [
                'uid'      => $uid,
                'fullname' => ($entity && $entity->getDisplayName()) ? $entity->getDisplayName() : $oauthInfo['user']['fullname'],
                'username' => $oauthInfo['user']['username'],
                'avatar'   => ($entity && $entity->getPrimaryAvatar())
                    ? ($avatar = FactoryMediaObject::of( $entity->getPrimaryAvatar() )->get_Link().'/profile.jpg' )
                    : (string) \Module\HttpFoundation\Actions::url(
                        'main/profile/delegate/profile_pic'
                        , [ 'userid' => $oauthInfo['user']['uid'] ]
                        , Url::ABSOLUTE_URL | Url::DEFAULT_INSTRUCT
                ),
                'privacy_stat' => ($entity && $entity->getPrivacyStatus())
                    ? $entity->getPrivacyStatus() : EntityProfile::PRIVACY_PUBLIC,


                'trusted'      => \Module\Profile\Actions::IsUserTrusted($uid),
            ];

            if ($mode == 'contact')
                // Include Mobile
                $r[$uid]['mobile'] = $oauthInfo['user']['mobile'];
        }

        return $r;
    }
}
