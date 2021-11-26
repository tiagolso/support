<?php
/*********************************************************************
    profile.php

    Manage client profile. This will allow a logged-in user to manage
    his/her own public (non-internal) information

    Peter Rotich <peter@osticket.com>
    Jared Hancock <jared@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
    $Id: $
**********************************************************************/
require 'client.inc.php';

$inc = 'register.inc.php';

$errors = array();

if (!$cfg || !$cfg->isClientRegistrationEnabled()) {
    Http::redirect('index.php');
}

elseif ($thisclient) {
    // Convidado se registrando para uma conta
    if ($thisclient->isGuest()) {
        foreach ($thisclient->getForms() as $f) {
            if ($f->get('object_type') == 'U') {
                $user_form = $f;
                $user_form->getField('email')->configure('disabled', true);
            }
        }
    }
    // Cliente existente (com uma conta) atualizando perfil
    else {
        $user = User::lookup($thisclient->getId());
        $content = Page::lookupByType('registration-thanks');
        $inc = isset($_GET['confirmed'])
            ? 'register.confirmed.inc.php' : 'profile.inc.php';
    }
}

if ($user && $_POST) {
    if ($acct = $thisclient->getAccount()) {
       $acct->update($_POST, $errors);
    }
    if (!$errors && $user->updateInfo($_POST, $errors))
        Http::redirect('tickets.php');
}
elseif ($_POST) {
    $user_form = UserForm::getUserForm()->getForm($_POST);
    if ($thisclient) {
        $user_form->getField('email')->configure('disabled', true);
        $user_form->getField('email')->value = $thisclient->getEmail();
        $_POST['email'] = $thisclient->getEmail();
    }

    if (!$user_form->isValid(function($f) { return !$f->isVisibleToUsers(); }))
        $errors['err'] = __('Informa&ccedil&otildees do cliente incompletas');
    elseif (!$_POST['backend'] && !$_POST['passwd1'])
        $errors['passwd1'] = __('Nova senha requerida');
    elseif (!$_POST['backend'] && $_POST['passwd2'] != $_POST['passwd1'])
        $errors['passwd1'] = __('Senhas n&atildeo s&atildeo iguais');
    else {
        try {
            UserAccount::checkPassword($_POST['passwd1']);
        } catch (BadPassword $ex) {
             $errors['passwd1'] = $ex->getMessage();
        }
    }

    if ($errors)
        $errors['err'] = $errors['err'] ?: __('Incapaz de registrar conta. Veja as mensagens abaixo.');
    // XXX: The email will always be in use already if a guest is logged in
    // and is registering for an account. Instead,
    elseif (($addr = $user_form->getField('email')->getClean())
            && ClientAccount::lookupByUsername($addr)) {
        $user_form->getField('email')->addError(
            sprintf(__('Email j&aacute registrado. Gostaria de %1$s iniciar sessão %2$s?'),
            '<a href="login.php?e='.urlencode($addr).'" style="color:inherit"><strong>',
            '</strong></a>'));
        $errors['err'] = __('Incapaz de registrar conta. Veja as mensagens abaixo.');
    }
    elseif (!$addr)
        $errors['email'] = sprintf(__('%s is a required field'), $user_form->getField('email')->getLocal('label'));
    elseif (!$user_form->getField('name')->getClean())
        $errors['name'] = sprintf(__('%s is a required field'), $user_form->getField('name')->getLocal('label'));
    // Registration for existing users
    elseif ($addr && ($user = User::lookupByEmail($addr)) && !$user->updateInfo($_POST, $errors))
      $errors['err'] = __('Incapaz de registrar conta. Veja as mensagens abaixo.');
    // Users created from ClientCreateRequest
    elseif (isset($_POST['backend']) && !($user = User::fromVars($user_form->getClean())))
        $errors['err'] = __('Incapaz de criar conta local. Veja as mensagens abaixo.');
    // New users and users registering from a ticket access link
    elseif (!$user && !($user = $thisclient ?: User::fromForm($user_form)))
        $errors['err'] = __('Incapaz de registrar sua conta. Veja as mensagens abaixo.');
    else {
        if (!($acct = ClientAccount::createForUser($user)))
            $errors['err'] = __('Incapaz de criar uma nova conta.')
                .' '.__('Internal error occurred');
        elseif (!$acct->update($_POST, $errors))
            $errors['err'] = __('Erros ao configurar seu perfil. Veja as mensagens abaixo.');
    }

    if (!$errors) {
        switch ($_POST['do']) {
        case 'create':
            $content = Page::lookupByType('registration-confirm');
            $inc = 'register.confirm.inc.php';
            $acct->sendConfirmEmail();
            break;
        case 'import':
            if ($bk = UserAuthenticationBackend::getBackend($_POST['backend'])) {
                $cl = new ClientSession(new EndUser($user));
                if (!$bk->supportsInteractiveAuthentication())
                    $acct->set('backend', null);
                $acct->confirm();
                if ($user = $bk->login($cl, $bk))
                    Http::redirect('tickets.php');
            }
            break;
        }
    }

    if ($errors && $user && $user != $thisclient)
        $user->delete();
}

include(CLIENTINC_DIR.'header.inc.php');
include(CLIENTINC_DIR.$inc);
include(CLIENTINC_DIR.'footer.inc.php');
