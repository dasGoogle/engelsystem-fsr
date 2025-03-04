<?php

namespace Engelsystem\Test\Unit\Controllers;

use Carbon\Carbon;
use Engelsystem\Config\Config;
use Engelsystem\Controllers\SettingsController;
use Engelsystem\Http\Exceptions\HttpNotFound;
use Engelsystem\Http\Response;
use Engelsystem\Models\User\Settings;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Session\Session;
use Engelsystem\Helpers\Authenticator;
use Engelsystem\Test\Unit\HasDatabase;
use Engelsystem\Http\UrlGenerator;
use Engelsystem\Models\User\User;
use Engelsystem\Http\Validation\Validator;
use Engelsystem\Http\Exceptions\ValidationException;

class SettingsControllerTest extends ControllerTest
{
    use HasDatabase;

    /** @var Authenticator|MockObject */
    protected $auth;

    /** @var User */
    protected $user;

    /** @var SettingsController */
    protected $controller;

    protected function setUpProfileTest()
    {
        $body = [
            'pronoun'                => 'Herr',
            'first_name'             => 'John',
            'last_name'              => 'Doe',
            'planned_arrival_date'   => '2022-01-01',
            'planned_departure_date' => '2022-01-02',
            'dect'                   => '1234',
            'mobile'                 => '0123456789',
            'mobile_show'            => true,
            'email'                  => 'a@bc.de',
            'email_shiftinfo'        => true,
            'email_news'             => true,
            'email_human'            => true,
            'email_goody'            => true,
            'shirt_size'             => 'S',
        ];
        $this->request = $this->request->withParsedBody($body);
        $this->setExpects(
            $this->response,
            'redirectTo',
            ['http://localhost/settings/profile'],
            $this->response,
            $this->atLeastOnce()
        );

        config([
            'enable_pronoun'         => true,
            'enable_user_name'       => true,
            'enable_planned_arrival' => true,
            'enable_dect'            => true,
            'enable_mobile_show'     => true,
            'enable_goody'           => true,
            'enable_tshirt_size'     => true,
        ]);

        $this->setExpects($this->auth, 'user', null, $this->user, $this->atLeastOnce());

        $this->controller = $this->app->make(SettingsController::class);
        $this->controller->setValidator(new Validator());

        return $body;
    }

    /**
     * @covers \Engelsystem\Controllers\SettingsController::profile
     */
    public function testProfile()
    {
        $this->setExpects($this->auth, 'user', null, $this->user, $this->once());
        /** @var Response|MockObject $response */
        $this->response->expects($this->once())
            ->method('withView')
            ->willReturnCallback(function ($view, $data) {
                $this->assertEquals('pages/settings/profile', $view);
                $this->assertArrayHasKey('user', $data);
                $this->assertEquals($this->user, $data['user']);
                return $this->response;
            });

        $this->controller = $this->app->make(SettingsController::class);
        $this->controller->profile();
    }

    /**
     * @covers \Engelsystem\Controllers\SettingsController::saveProfile
     * @covers \Engelsystem\Controllers\SettingsController::getSaveProfileRules
     */
    public function testSaveProfile()
    {
        $body = $this->setUpProfileTest();
        $this->controller->saveProfile($this->request);

        $this->assertEquals($body['pronoun'], $this->user->personalData->pronoun);
        $this->assertEquals($body['first_name'], $this->user->personalData->first_name);
        $this->assertEquals($body['last_name'], $this->user->personalData->last_name);
        $this->assertEquals(
            $body['planned_arrival_date'],
            $this->user->personalData->planned_arrival_date->format('Y-m-d')
        );
        $this->assertEquals(
            $body['planned_departure_date'],
            $this->user->personalData->planned_departure_date->format('Y-m-d')
        );
        $this->assertEquals($body['dect'], $this->user->contact->dect);
        $this->assertEquals($body['mobile'], $this->user->contact->mobile);
        $this->assertEquals($body['mobile_show'], $this->user->settings->mobile_show);
        $this->assertEquals($body['email'], $this->user->email);
        $this->assertEquals($body['email_shiftinfo'], $this->user->settings->email_shiftinfo);
        $this->assertEquals($body['email_news'], $this->user->settings->email_news);
        $this->assertEquals($body['email_human'], $this->user->settings->email_human);
        $this->assertEquals($body['email_goody'], $this->user->settings->email_goody);
        $this->assertEquals($body['shirt_size'], $this->user->personalData->shirt_size);
    }

    /**
     * @covers \Engelsystem\Controllers\SettingsController::saveProfile
     */
    public function testSaveProfileThrowsErrorOnInvalidArrival()
    {
        $this->setUpProfileTest();
        config(['buildup_start' => new Carbon('2022-01-02')]); // arrival before buildup
        $this->controller->saveProfile($this->request);
        $this->assertHasNotification('settings.profile.planned_arrival_date.invalid', 'errors');
    }

    /**
     * @covers \Engelsystem\Controllers\SettingsController::saveProfile
     */
    public function testSaveProfileThrowsErrorOnInvalidDeparture()
    {
        $this->setUpProfileTest();
        config(['teardown_end' => new Carbon('2022-01-01')]); // departure after teardown
        $this->controller->saveProfile($this->request);
        $this->assertHasNotification('settings.profile.planned_departure_date.invalid', 'errors');
    }

    /**
     * @covers \Engelsystem\Controllers\SettingsController::saveProfile
     */
    public function testSaveProfileIgnoresPronounIfDisabled()
    {
        $this->setUpProfileTest();
        config(['enable_pronoun' => false]);
        $this->controller->saveProfile($this->request);
        $this->assertEquals('', $this->user->personalData->pronoun);
    }

    /**
     * @covers \Engelsystem\Controllers\SettingsController::saveProfile
     */
    public function testSaveProfileIgnoresFirstAndLastnameIfDisabled()
    {
        $this->setUpProfileTest();
        config(['enable_user_name' => false]);
        $this->controller->saveProfile($this->request);
        $this->assertEquals('', $this->user->personalData->first_name);
        $this->assertEquals('', $this->user->personalData->last_name);
    }

    /**
     * @covers \Engelsystem\Controllers\SettingsController::saveProfile
     */
    public function testSaveProfileIgnoresArrivalDatesIfDisabled()
    {
        $this->setUpProfileTest();
        config(['enable_planned_arrival' => false]);
        $this->controller->saveProfile($this->request);
        $this->assertEquals('', $this->user->personalData->planned_arrival_date);
        $this->assertEquals('', $this->user->personalData->planned_departure_date);
    }

    /**
     * @covers \Engelsystem\Controllers\SettingsController::saveProfile
     */
    public function testSaveProfileIgnoresDectIfDisabled()
    {
        $this->setUpProfileTest();
        config(['enable_dect' => false]);
        $this->controller->saveProfile($this->request);
        $this->assertEquals('', $this->user->contact->dect);
    }

    /**
     * @covers \Engelsystem\Controllers\SettingsController::saveProfile
     */
    public function testSaveProfileIgnoresMobileShowIfDisabled()
    {
        $this->setUpProfileTest();
        config(['enable_mobile_show' => false]);
        $this->controller->saveProfile($this->request);
        $this->assertFalse($this->user->settings->mobile_show);
    }

    /**
     * @covers \Engelsystem\Controllers\SettingsController::saveProfile
     */
    public function testSaveProfileIgnoresEmailGoodyIfDisabled()
    {
        $this->setUpProfileTest();
        config(['enable_goody' => false]);
        $this->controller->saveProfile($this->request);
        $this->assertFalse($this->user->settings->email_goody);
    }

    /**
     * @covers \Engelsystem\Controllers\SettingsController::saveProfile
     */
    public function testSaveProfileIgnoresTShirtSizeIfDisabled()
    {
        $this->setUpProfileTest();
        config(['enable_tshirt_size' => false]);
        $this->controller->saveProfile($this->request);
        $this->assertEquals('', $this->user->personalData->shirt_size);
    }

    /**
     * @covers \Engelsystem\Controllers\SettingsController::password
     */
    public function testPassword()
    {
        /** @var Response|MockObject $response */
        $this->response->expects($this->once())
        ->method('withView')
        ->willReturnCallback(function ($view, $data) {
            $this->assertEquals('pages/settings/password', $view);

            return $this->response;
        });

        $this->controller->password();
    }

    /**
     * @covers \Engelsystem\Controllers\SettingsController::savePassword
     */
    public function testSavePassword()
    {
        $body = [
            'password' => 'password',
            'new_password' => 'newpassword',
            'new_password2' => 'newpassword'
        ];
        $this->request = $this->request->withParsedBody($body);

        $this->setExpects($this->auth, 'user', null, $this->user, $this->once());
        $this->setExpects($this->auth, 'verifyPassword', [$this->user, 'password'], true, $this->once());
        $this->setExpects($this->auth, 'setPassword', [$this->user, 'newpassword'], null, $this->once());
        $this->setExpects(
            $this->response,
            'redirectTo',
            ['http://localhost/settings/password'],
            $this->response,
            $this->once()
        );

        $this->controller->savePassword($this->request);

        $this->assertTrue($this->log->hasInfoThatContains('User set new password.'));

        /** @var Session $session */
        $session = $this->app->get('session');
        $messages = $session->get('messages');
        $this->assertEquals('settings.password.success', $messages[0]);
    }

    /**
     * @covers \Engelsystem\Controllers\SettingsController::savePassword
     */
    public function testSavePasswordWhenEmpty()
    {
        $this->user->password = '';
        $this->user->save();

        $body = [
            'new_password'  => 'anotherpassword',
            'new_password2' => 'anotherpassword'
        ];
        $this->request = $this->request->withParsedBody($body);

        $this->setExpects($this->auth, 'user', null, $this->user, $this->once());
        $this->setExpects($this->auth, 'setPassword', [$this->user, 'anotherpassword'], null, $this->once());
        $this->setExpects(
            $this->response,
            'redirectTo',
            ['http://localhost/settings/password'],
            $this->response,
            $this->once()
        );

        $this->controller->savePassword($this->request);
    }

    /**
     * @covers \Engelsystem\Controllers\SettingsController::savePassword
     */
    public function testSavePasswordWrongOldPassword()
    {
        $body = [
            'password' => 'wrongpassword',
            'new_password' => 'newpassword',
            'new_password2' => 'newpassword'
        ];
        $this->request = $this->request->withParsedBody($body);

        $this->setExpects($this->auth, 'user', null, $this->user, $this->once());
        $this->setExpects($this->auth, 'verifyPassword', [$this->user, 'wrongpassword'], false, $this->once());
        $this->setExpects($this->auth, 'setPassword', null, null, $this->never());
        $this->setExpects(
            $this->response,
            'redirectTo',
            ['http://localhost/settings/password'],
            $this->response,
            $this->once()
        );

        $this->controller->savePassword($this->request);

        /** @var Session $session */
        $session = $this->app->get('session');
        $errors = $session->get('errors');
        $this->assertEquals('auth.password.error', $errors[0]);
    }

    /**
     * @covers \Engelsystem\Controllers\SettingsController::savePassword
     */
    public function testSavePasswordMismatchingNewPassword()
    {
        $body = [
            'password' => 'password',
            'new_password' => 'newpassword',
            'new_password2' => 'wrongpassword'
        ];
        $this->request = $this->request->withParsedBody($body);

        $this->setExpects($this->auth, 'user', null, $this->user, $this->once());
        $this->setExpects($this->auth, 'verifyPassword', [$this->user, 'password'], true, $this->once());
        $this->setExpects($this->auth, 'setPassword', null, null, $this->never());
        $this->setExpects(
            $this->response,
            'redirectTo',
            ['http://localhost/settings/password'],
            $this->response,
            $this->once()
        );

        $this->controller->savePassword($this->request);

        /** @var Session $session */
        $session = $this->app->get('session');
        $errors = $session->get('errors');
        $this->assertEquals('validation.password.confirmed', $errors[0]);
    }

    /**
     * @return array
     */
    public function savePasswordValidationProvider(): array
    {
        return [
            [null, 'newpassword', 'newpassword'],
            ['password', null, 'newpassword'],
            ['password', 'newpassword', null],
            ['password', 'short', 'short']
        ];
    }

    /**
     * @covers \Engelsystem\Controllers\SettingsController::savePassword
     * @dataProvider savePasswordValidationProvider
     * @param string $password
     * @param string $new_password
     * @param string $new_password2
     */
    public function testSavePasswordValidation(
        ?string $password,
        ?string $newPassword,
        ?string $newPassword2
    ) {
        $body = [
            'password' => $password,
            'new_password' => $newPassword,
            'new_password2' => $newPassword2
        ];
        $this->request = $this->request->withParsedBody($body);

        $this->setExpects($this->auth, 'user', null, $this->user, $this->once());
        $this->setExpects($this->auth, 'setPassword', null, null, $this->never());

        $this->expectException(ValidationException::class);

        $this->controller->savePassword($this->request);
    }

    /**
     * @testdox theme: underNormalConditions -> returnsCorrectViewAndData
     * @covers \Engelsystem\Controllers\SettingsController::theme
     */
    public function testThemeUnderNormalConditionReturnsCorrectViewAndData()
    {
        $this->setExpects($this->auth, 'user', null, $this->user, $this->once());

        /** @var Response|MockObject $response */
        $this->response->expects($this->once())
            ->method('withView')
            ->willReturnCallback(function ($view, $data) {
                $this->assertEquals('pages/settings/theme', $view);
                $this->assertArrayHasKey('settings_menu', $data);
                $this->assertArrayHasKey('themes', $data);
                $this->assertArrayHasKey('current_theme', $data);
                $this->assertEquals([0 => 'Engelsystem light', 1 => 'Engelsystem dark'], $data['themes']);
                $this->assertEquals(1, $data['current_theme']);

                return $this->response;
            });

        $this->controller->theme();
    }

    /**
     * @testdox saveTheme: withNoSelectedThemeGiven -> throwsException
     * @covers \Engelsystem\Controllers\SettingsController::saveTheme
     */
    public function testSaveThemeWithNoSelectedThemeGivenThrowsException()
    {
        $this->setExpects($this->auth, 'user', null, $this->user, $this->once());
        $this->expectException(ValidationException::class);

        $this->controller->saveTheme($this->request);
    }

    /**
     * @testdox saveTheme: withUnknownSelectedThemeGiven -> throwsException
     * @covers \Engelsystem\Controllers\SettingsController::saveTheme
     */
    public function testSaveThemeWithUnknownSelectedThemeGivenThrowsException()
    {
        $this->request = $this->request->withParsedBody(['select_theme' => 2]);

        $this->setExpects($this->auth, 'user', null, $this->user, $this->once());
        $this->expectException(HttpNotFound::class);

        $this->controller->saveTheme($this->request);
    }

    /**
     * @testdox saveTheme: withKnownSelectedThemeGiven -> savesThemeAndRedirect
     * @covers \Engelsystem\Controllers\SettingsController::saveTheme
     */
    public function testSaveThemeWithKnownSelectedThemeGivenSavesThemeAndRedirect()
    {
        $this->assertEquals(1, $this->user->settings->theme);
        $this->setExpects($this->auth, 'user', null, $this->user, $this->once());
        $this->response->expects($this->once())
            ->method('redirectTo')
            ->with('http://localhost/settings/theme')
            ->willReturn($this->response);

        $this->request = $this->request->withParsedBody(['select_theme' => 0]);

        $this->controller->saveTheme($this->request);

        $this->assertEquals(0, $this->user->settings->theme);
    }

    /**
     * @testdox language: underNormalConditions -> returnsCorrectViewAndData
     * @covers \Engelsystem\Controllers\SettingsController::language
     */
    public function testLanguageUnderNormalConditionReturnsCorrectViewAndData()
    {
        $this->setExpects($this->auth, 'user', null, $this->user, $this->once());

        /** @var Response|MockObject $response */
        $this->response->expects($this->once())
            ->method('withView')
            ->willReturnCallback(function ($view, $data) {
                $this->assertEquals('pages/settings/language', $view);
                $this->assertArrayHasKey('settings_menu', $data);
                $this->assertArrayHasKey('languages', $data);
                $this->assertArrayHasKey('current_language', $data);
                $this->assertEquals(['en_US' => 'English', 'de_DE' => 'Deutsch'], $data['languages']);
                $this->assertEquals('en_US', $data['current_language']);

                return $this->response;
            });

        $this->controller->language();
    }

    /**
     * @testdox saveLanguage: withNoSelectedLanguageGiven -> throwsException
     * @covers \Engelsystem\Controllers\SettingsController::saveLanguage
     */
    public function testSaveLanguageWithNoSelectedLanguageGivenThrowsException()
    {
        $this->setExpects($this->auth, 'user', null, $this->user, $this->once());
        $this->expectException(ValidationException::class);

        $this->controller->saveLanguage($this->request);
    }

    /**
     * @testdox saveLanguage: withUnknownSelectedLanguageGiven -> throwsException
     * @covers \Engelsystem\Controllers\SettingsController::saveLanguage
     */
    public function testSaveLanguageWithUnknownSelectedLanguageGivenThrowsException()
    {
        $this->request = $this->request->withParsedBody(['select_language' => 'unknown']);

        $this->setExpects($this->auth, 'user', null, $this->user, $this->once());
        $this->expectException(HttpNotFound::class);

        $this->controller->saveLanguage($this->request);
    }

    /**
     * @testdox saveLanguage: withKnownSelectedLanguageGiven -> savesLanguageAndUpdatesSessionAndRedirect
     * @covers \Engelsystem\Controllers\SettingsController::saveLanguage
     */
    public function testSaveLanguageWithKnownSelectedLanguageGivenSavesLanguageAndUpdatesSessionAndRedirect()
    {
        $this->assertEquals('en_US', $this->user->settings->language);
        $this->session->set('locale', 'en_US');

        $this->setExpects($this->auth, 'user', null, $this->user, $this->once());
        $this->response->expects($this->once())
            ->method('redirectTo')
            ->with('http://localhost/settings/language')
            ->willReturn($this->response);

        $this->request = $this->request->withParsedBody(['select_language' => 'de_DE']);

        $this->controller->saveLanguage($this->request);

        $this->assertEquals('de_DE', $this->user->settings->language);
        $this->assertEquals('de_DE', $this->session->get('locale'));
    }

    /**
     * @covers \Engelsystem\Controllers\SettingsController::__construct
     * @covers \Engelsystem\Controllers\SettingsController::oauth
     */
    public function testOauth()
    {
        $providers = ['foo' => ['lorem' => 'ipsum']];
        config(['oauth' => $providers]);
        $this->response->expects($this->once())
            ->method('withView')
            ->willReturnCallback(function ($view, $data) use ($providers) {
                $this->assertEquals('pages/settings/oauth', $view);
                $this->assertArrayHasKey('information', $data);
                $this->assertArrayHasKey('providers', $data);
                $this->assertEquals($providers, $data['providers']);

                return $this->response;
            });

        $this->controller->oauth();
    }

    /**
     * @covers \Engelsystem\Controllers\SettingsController::oauth
     */
    public function testOauthNotConfigured()
    {
        config(['oauth' => []]);

        $this->expectException(HttpNotFound::class);
        $this->controller->oauth();
    }

    /**
     * @covers \Engelsystem\Controllers\SettingsController::settingsMenu
     * @covers \Engelsystem\Controllers\SettingsController::checkOauthHidden
     */
    public function testSettingsMenuWithOAuth()
    {
        $providers = ['foo' => ['lorem' => 'ipsum']];
        $providersHidden = ['foo' => ['lorem' => 'ipsum', 'hidden' => true]];
        config(['oauth' => $providers]);

        $this->assertEquals([
            'http://localhost/settings/profile' => 'settings.profile',
            'http://localhost/settings/password' => 'settings.password',
            'http://localhost/settings/language' => 'settings.language',
            'http://localhost/settings/theme' => 'settings.theme',
            'http://localhost/settings/oauth' => ['title' => 'settings.oauth', 'hidden' => false]
        ], $this->controller->settingsMenu());

        config(['oauth' => $providersHidden]);
        $this->assertEquals([
            'http://localhost/settings/profile' => 'settings.profile',
            'http://localhost/settings/password' => 'settings.password',
            'http://localhost/settings/language' => 'settings.language',
            'http://localhost/settings/theme' => 'settings.theme',
            'http://localhost/settings/oauth' => ['title' => 'settings.oauth', 'hidden' => true]
        ], $this->controller->settingsMenu());
    }

    /**
     * @covers \Engelsystem\Controllers\SettingsController::settingsMenu
     */
    public function testSettingsMenuWithoutOAuth()
    {
        config(['oauth' => []]);

        $this->assertEquals([
            'http://localhost/settings/profile' => 'settings.profile',
            'http://localhost/settings/password' => 'settings.password',
            'http://localhost/settings/language' => 'settings.language',
            'http://localhost/settings/theme' => 'settings.theme'
        ], $this->controller->settingsMenu());
    }

    /**
     * Setup environment
     */
    public function setUp(): void
    {
        parent::setUp();

        $themes = [
            0 => ['name' => 'Engelsystem light'],
            1 => ['name' => 'Engelsystem dark']
        ];
        $languages = [
            'en_US' => 'English',
            'de_DE' => 'Deutsch'
        ];
        $tshirt_sizes = ['S' => 'Small'];
        $this->config = new Config([
            'min_password_length' => 6,
            'themes' => $themes,
            'locales' => $languages,
            'tshirt_sizes' => $tshirt_sizes
        ]);
        $this->app->instance('config', $this->config);
        $this->app->instance(Config::class, $this->config);

        $this->app->bind('http.urlGenerator', UrlGenerator::class);

        $this->auth = $this->createMock(Authenticator::class);
        $this->app->instance(Authenticator::class, $this->auth);

        $this->user = User::factory()
            ->has(Settings::factory([
                'theme' => 1,
                'language' => 'en_US',
                'email_goody' => false,
                'mobile_show' => false,
            ]))
            ->create();

        $this->controller = $this->app->make(SettingsController::class);
        $this->controller->setValidator(new Validator());
    }
}
