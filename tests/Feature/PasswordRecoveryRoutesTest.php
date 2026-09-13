<?php

namespace Tests\Feature;

use Tests\TestCase;
class PasswordRecoveryRoutesTest extends TestCase
{
    /**
     * The recovery-method selection page exposes the canonical mnemonic URL.
     *
     * @return void
     */
    public function testMnemonicRecoveryUsesTheCanonicalForgotPasswordPath()
    {
        $response = $this->get('/forgotpassword');

        $response->assertOk();
        $response->assertSee('action="/forgotpassword/mnemonic"', false);
    }

    /**
     * The canonical mnemonic recovery route displays its form.
     *
     * @return void
     */
    public function testMnemonicRecoveryFormIsAvailableAtTheCanonicalPath()
    {
        $response = $this->get('/forgotpassword/mnemonic');

        $response->assertOk();
        $response->assertSee('Please enter your username, mnemonic and your new password');
    }
}
