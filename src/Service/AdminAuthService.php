<?php

namespace Ridibooks\Cms\Service;

use Ridibooks\Cms\Thrift\AdminMenu\AdminMenu as ThriftAdminMenu;
use Ridibooks\Cms\Thrift\ThriftService;
use Ridibooks\Cms\Util\UrlHelper;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**권한 설정 Service
 * @deprecated
 */
class AdminAuthService
{
    /**해당 유저가 볼 수 있는 메뉴를 가져온다.
     * @return array
     */
    public function getAdminMenu($user_id = null)
    {
        $user_service = new AdminUserService();
        $menus = $user_service->getAllMenus($user_id ?? LoginService::GetAdminID());
        
        $menus = $this->hideEmptyRootMenus($menus);

        $admin_menus = [];
        foreach ($menus as $menu) {
            if ($menu['is_use'] == 1 && $menu['is_show'] == 1) {
                $admin_menus[$menu['id']] = $menu;
            }
        }

        return array_map(function ($menu) {
            return new ThriftAdminMenu($menu);
        }, $admin_menus);
    }

    /**해당 유저의 모든 태그를 가져온다.
     * @return array
     */
    public function getAdminTag($user_id = null)
    {
        $user_service = new AdminUserService();
        return $user_service->getAdminUserTag($user_id ?? LoginService::GetAdminID());
    }

    /**해당 유저의 태그 ID 가져온다.
     * @return array
     */
    public function getAdminTagId()
    {
        $session_user_tagid = [];
        foreach ($this->getAdminTag() as $tag) {
            $session_user_tagid[] = $tag;
        }
        return $session_user_tagid;
    }

    /**적합한 로그인 상태인지 검사한다.
     * @return bool
     */
    public static function isValidLogin()
    {
        return !empty(LoginService::GetAdminID());
    }

    /**적합한 유저인지 검사한다.
     * @return bool
     */
    public static function isValidUser(string $user_id)
    {
        $user_service = new AdminUserService();
        $admin = $user_service->getUser($user_id ?? LoginService::GetAdminID());
        if (!$admin->id) {
            return false;
        }

        return $admin && $admin->is_use;
    }

    /**
     * @param $menu_raw
     * @return mixed
     */
    private function getUrlFromMenuUrl($menu_raw)
    {
        $url = preg_replace('/#.*/', '', $menu_raw['menu_url']);
        return $url;
    }

    /**해당 유저의 모든 권한을 가져온다.
     * @return array
     */
    public function getAdminAuth($user_id = null)
    {
        if (empty($user_id)) {
            $user_id = LoginService::GetAdminID();
        } 

        $user_service = new AdminUserService();
        $menu_auths = $user_service->getAllMenus($user_id);
        $ajax_auths = $user_service->getAllMenuAjaxList($user_id);
        $auths = array_merge($menu_auths, $ajax_auths);

        return $auths;
    }

    private static function parseUrlAuth($url)
    {
        $tokens = preg_split('/#/', $url);
        return [
            'url' => $tokens[0] ?? null,
            'hash' => $tokens[1] ?? null,
        ];
    }

    /**입력받은 url이 권한을 가지고 있는 url인지 검사<br/>
     * '/comm/'으로 시작하는 url은 권한을 타지 않는다. (개인정보 수정 등 로그인 한 유저가 공통적으로 사용할 수 있는 기능을 /comm/에 넣을 예정)
     * @param $check_url
     * @param $menu_url
     * @return bool
     */
    private static function isAuthUrl($check_url, $menu_url)
    {
        $auth_url = preg_replace('/(\?|#).*/', '', $menu_url);
        if (strpos($check_url, '/comm/')) { // /comm/으로 시작하는 url은 권한을 타지 않는다.
            return true;
        }
        if ($auth_url != '' && strpos($check_url, $auth_url) !== false) { //현재 url과 권한 url이 같은지 비교
            return true;
        }
        return false;
    }

    /**권한이 정확한지 확인
     * @param null $hash
     * @param $auth
     * @return bool
     */
    private static function isAuthCorrect($hash, $auth)
    {
        if (is_null($hash)) { //hash가 없는 경우 (보기 권한)
            return true;
        } elseif (is_array($hash)) { //hash가 array인 경우
            foreach ($hash as $h) {
                if (in_array($h, $auth)) {
                    return true;
                }
            }
        } elseif (is_array($auth) && in_array($hash, $auth)) {
            return true;
        } elseif ($auth == $hash) {
            return true;
        }
        return false;
    }

    public static function isWhiteListUrl($check_url)
    {
        $public_urls = [
            '/admin/book/pa',
            '/me', // 본인 정보 수정
            '/welcome',
            '/logout',
            '/login-azure',
            '/index.php',
            '/',
        ];

        return in_array($check_url, $public_urls);
    }

    public static function readUserAuth($user_id)
    {
        $user_service = new AdminUserService();
        $menu_urls = $user_service->getAllMenus($user_id, 'menu_url');
        $ajax_urls = $user_service->getAllMenuAjaxList($user_id, 'ajax_url');
        $urls = array_merge($menu_urls, $ajax_urls);

        return $urls;
    }

    public static function checkAuth($hash, $check_url, $auth_list)
    {
        if (self::isWhiteListUrl($check_url)) {
            return true;
        }

        foreach ($auth_list as $auth) {
            $auth = self::parseUrlAuth($auth);
            if (self::isAuthUrl($check_url, $auth['url'])
                && self::isAuthCorrect($hash, $auth['hash'] ?? [])) {
                return true;
            }
        }
        return false;
    }

        /**해당 URL의 Hash 권한이 있는지 검사한다.<br/>
     * @param $hash
     * @param $check_url
     * @return bool
     */
    public static function hasHashAuth($hash, $check_url, $admin_id = null)
    {
        if (empty($admin_id)) {
            $admin_id = LoginService::GetAdminID();
        }
        $auth_list = self::readUserAuth($admin_id);

        return self::checkAuth($hash, $check_url, $auth_list);
    }

    /**
     * @param Request $request
     * @return null|Response
     */
    public static function authorize($request)
    {
        $user_id = LoginService::GetAdminID();
        $request_uri = $request->getRequestUri();

        if (!self::isValidLogin() || !self::isValidUser($user_id)) {
            $login_url = '/login';
            if (!empty($request_uri) && $request_uri != '/login') {
                $login_url .= '?return_url=' . urlencode($request_uri);
            }

            return RedirectResponse::create($login_url);
        }

        $user_auth = self::readUserAuth($user_id);

        try {
            if (!self::checkAuth($hash, $request_uri, $user_auth)
                && !$_ENV['DEBUG']) {
                throw new \Exception("해당 권한이 없습니다.");
            }
        } catch (\Exception $e) {
            // 이상하지만 기존과 호환성 맞추기 위해
            if ($request->isXmlHttpRequest()) {
                return new Response($e->getMessage());
            } else { //일반 페이지
                return new Response(UrlHelper::printAlertHistoryBack($e->getMessage()));
            }
        }

        return null;
    }

    /**해당 URL의 Hash 권한 Array를 반환한다.
     * @param null $check_url
     * @param null $admin_id
     * @return array $hash_array
     */
    public static function getCurrentHashArray($check_url = null, $admin_id = null)
    {
        if (!isset($check_url) || trim($check_url) === '') {
            $check_url = $_SERVER['REQUEST_URI'];
        }

        $auths = self::readUserAuth($admin_id ?? LoginService::GetAdminID());
        $hash_array = self::getHashesFromMenus($check_url, $auths);

        return $hash_array;
    }

    /**
     * @param $check_url
     * @param $auths
     * @return array
     */
    public static function getHashesFromMenus($check_url, $auth_urls)
    {
        $auth_urls = array_filter($auth_urls, function ($url) use ($check_url) {
            return self::isAuthUrl($check_url, $url);
        });

        $hash_array = array_map(function($url) {
            return self::parseUrlAuth($url)['hash'];
        }, $auth_urls);

        return $hash_array;
    }

    //비어있는 최상위 메뉴는 안보이게
    private function hideEmptyRootMenus($menus)
    {
        foreach ($menus as $key => $menu) {
            $current_url = $this->getUrlFromMenuUrl($menu);
            $current_depth = $menu['menu_deep'];
            $currrent_menu_is_top = ($current_depth == 0 && strlen($current_url) == 0);

            //이전 메뉴와 비교
            if ($key != 0) {
                $last_key = $key - 1;
                $last_menu = $menus[$last_key];

                $prev_url = $this->getUrlFromMenuUrl($last_menu);
                $prev_depth = $last_menu['menu_deep'];
                $prev_menu_is_top = ($prev_depth == 0 && strlen($prev_url) == 0);

                if ($prev_menu_is_top && $currrent_menu_is_top) {
                    $menus[$last_key]['is_show'] = false;
                }
            }

            //tail 체크
            if ($key == count($menus) - 1 && $currrent_menu_is_top) {
                $menus[$key]['is_show'] = false;
            }
        }
        return $menus;
    }
}
