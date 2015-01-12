<?php

class AccountConfirmSubmission {
	/* User making the confirmation */
	protected $admin;
	/** @var UserAccountRequest */
	protected $accReq;
	/* Admin-overridable name and fields filled from request form */
	protected $userName;
	protected $bio;
	protected $type;
	/** @var array */
	protected $areas;

	protected $action;
	protected $reason;

	public function __construct( User $admin, UserAccountRequest $accReq, array $params ) {
		$this->admin = $admin;
		$this->accountReq = $accReq;
		$this->userName = trim( $params['userName'] );
		$this->bio = trim( $params['bio'] );
		$this->type = $params['type'];
		$this->areas = $params['areas'];
		$this->action = $params['action'];
		$this->reason = $params['reason'];
	}

	/**
	 * Attempt to validate and submit this data to the DB
	 * @param $context IContextSource
	 * @return array( true or error key string, html error msg or null )
	 */
	public function submit( IContextSource $context ) {
		# Make sure that basic permissions are checked
		if ( !$this->admin->getID() || !$this->admin->isAllowed( 'confirmaccount' ) ) {
			return array( 'accountconf_permission_denied', $context->msg( 'badaccess-group0' )->escaped() );
		} elseif ( wfReadOnly() ) {
			return array( 'accountconf_readonly', $context->msg( 'badaccess-group0' )->escaped() );
		}
		if ( $this->action === 'spam' ) {
			return $this->spamRequest( $context );
		} elseif ( $this->action === 'reject' ) {
			return $this->rejectRequest( $context );
		} elseif ( $this->action === 'hold' ) {
			return $this->holdRequest( $context );
		} elseif ( $this->action === 'accept' ) {
			return $this->acceptRequest( $context );
		} elseif ($this->action === 'delete') {
			return $this->deleteRequest($context);
		} else {
			return array( 'accountconf_bad_action', $context->msg( 'confirmaccount-badaction' )->escaped() );
		}
	}

	protected function spamRequest( IContextSource $context ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->begin();

		$ok = $this->accountReq->markRejected( $this->admin, wfTimestampNow(), '' );
		if ( $ok ) {
			# Clear cache for notice of how many account requests there are
			ConfirmAccount::clearAccountRequestCountCache();
		}

		$dbw->commit();
		return array( true, null );
	}
	
	protected function deleteRequest( IContextSource $context ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->begin();

		$ok = $this->accountReq->remove();
		ConfirmAccount::clearAccountRequestCountCache();

		$dbw->commit();
		return array( true, null );
	}

	protected function rejectRequest( IContextSource $context ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->begin();

		$ok = $this->accountReq->markRejected( $this->admin, wfTimestampNow(), $this->reason );
		if ( $ok ) {
			/*
			# Make proxy user to email a rejection message :(
			$u = User::newFromName( $this->accountReq->getName(), false );
			$u->setEmail( $this->accountReq->getEmail() );
			# Send out a rejection email...
			if ( $this->reason != '' ) {
				$emailBody = $context->msg( 'confirmaccount-email-body4',
					$u->getName(), $this->reason )->inContentLanguage()->text();
			} else {
				$emailBody = $context->msg( 'confirmaccount-email-body3',
					$u->getName() )->inContentLanguage()->text();
			}
			$result = $u->sendMail(
				$context->msg( 'confirmaccount-email-subj' )->inContentLanguage()->text(),
				$emailBody
			);
			if ( !$result->isOk() ) {
				$dbw->rollback();
				return array( 'accountconf_mailerror',
					$context->msg( 'mailerror' )->rawParams( $context->getOutput()->parse( $result->getWikiText() ) )->text() );
			}
			# Clear cache for notice of how many account requests there are
			*/
			ConfirmAccount::clearAccountRequestCountCache();
		}

		$dbw->commit();
		return array( true, null );
	}

	protected function holdRequest( IContextSource $context ) {
		# Make proxy user to email a message
		$u = User::newFromName( $this->accountReq->getName(), false );
		$u->setEmail( $this->accountReq->getEmail() );

		# Pointless without a summary...
		if ( $this->reason == '' ) {
			return array( 'accountconf_needreason', $context->msg( 'confirmaccount-needreason' )->escaped() );
		}

		$dbw = wfGetDB( DB_MASTER );
		$dbw->begin();

		# If not already held or deleted, mark as held
		$ok = $this->accountReq->markHeld( $this->admin, wfTimestampNow(), $this->reason );
		if ( !$ok ) { // already held or deleted?
			$dbw->rollback();
			return array( 'accountconf_canthold', $context->msg( 'confirmaccount-canthold' )->escaped() );
		}

		# Send out a request hold email...
		/*$result = $u->sendMail(
			$context->msg( 'confirmaccount-email-subj' )->inContentLanguage()->text(),
			$context->msg( 'confirmaccount-email-body5', $u->getName(), $this->reason )->inContentLanguage()->text()
		);
		if ( !$result->isOk() ) {
			$dbw->rollback();
			return array( 'accountconf_mailerror',
				$context->msg( 'mailerror' )->rawParams( $context->getOutput()->parse( $result->getWikiText() ) )->text() );
		}*/

		# Clear cache for notice of how many account requests there are
		ConfirmAccount::clearAccountRequestCountCache();

		$dbw->commit();
		return array( true, null );
	}

	protected function acceptRequest( IContextSource $context ) {
		global $wgAuth, $wgAccountRequestTypes, $wgConfirmAccountSaveInfo;
		global $wgConfirmAccountRequestFormItems, $wgConfirmAccountFSRepos;

		$formConfig = $wgConfirmAccountRequestFormItems; // convience
		$accReq = $this->accountReq; // convenience

		# Now create user and check if the name is valid
		$user = User::newFromName( $this->userName, 'creatable' );
		if ( !$user ) {
			return array( 'accountconf_invalid_name', $context->msg( 'noname' )->escaped() );
		}

		# Check if account name is already in use
		if ( 0 != $user->idForName() || $wgAuth->userExists( $user->getName() ) ) {
			return array( 'accountconf_user_exists', $context->msg( 'userexists' )->escaped() );
		}

		$dbw = wfGetDB( DB_MASTER );
		$dbw->begin();

		# extract password
		$els = explode(chr(1), $accReq->getNotes());
		$p = end($els);

		# Insert the new user into the DB...
		$tokenExpires = $accReq->getEmailTokenExpires();
		$authenticated = $accReq->getEmailAuthTimestamp();
		$params = array(
			# Set the user's real name
			'real_name'           => $accReq->getRealName(),
			# Set the temporary password
			'password'       	  => $p,
			# VERY important to set email now. Otherwise the user
			# will have to request a new password at the login screen...
			'email'               => $accReq->getEmail(),
			# Import email address confirmation status
			'email_authenticated' => $dbw->timestampOrNull( $authenticated ),
			'email_token_expires' => $dbw->timestamp( $tokenExpires ),
			'email_token'         => $accReq->getEmailToken()
		);
		$user = User::createNew( $user->getName(), $params );

		# Grant any necessary rights (exclude blank or dummy groups)
		$group = self::getGroupFromType( $this->type );
		if ( $group != '' && $group != 'user' && $group != '*' ) {
			$user->addGroup( $group );
		}

		$acd_id = null; // used for rollback cleanup
		# Save account request data to credentials system
		if ( $wgConfirmAccountSaveInfo ) {
			$key = $accReq->getFileStorageKey();
			# Copy any attached files to new storage group
			if ( $formConfig['CV']['enabled'] && $key ) {
				$repoOld = new FSRepo( $wgConfirmAccountFSRepos['accountreqs'] );
				$repoNew = new FSRepo( $wgConfirmAccountFSRepos['accountcreds'] );

				$pathRel = UserAccountRequest::relPathFromKey( $key );
				$oldPath = $repoOld->getZonePath( 'public' ) . '/' . $pathRel;

				$triplet = array( $oldPath, 'public', $pathRel );
				$status = $repoNew->storeBatch( array( $triplet ) ); // copy!
				if ( !$status->isOK() ) {
					$dbw->rollback();
					# DELETE new rows in case there was a COMMIT somewhere
					$this->acceptRequest_rollback( $dbw, $user->getId(), $acd_id );
					return array( 'accountconf_copyfailed',
						$context->getOutput()->parse( $status->getWikiText() ) );
				}
			}
			$acd_id = $dbw->nextSequenceValue( 'account_credentials_acd_id_seq' );
			# Move request data into a separate table
			$dbw->insert( 'account_credentials',
				array(
					'acd_user_id'             => $user->getID(),
					'acd_real_name'           => $accReq->getRealName(),
					'acd_email'               => $accReq->getEmail(),
					'acd_email_authenticated' => $dbw->timestampOrNull( $authenticated ),
					'acd_bio'                 => $accReq->getBio(),
					'acd_notes'               => $accReq->getNotes(),
					'acd_urls'                => $accReq->getUrls(),
					'acd_ip'                  => $accReq->getIP(),
					'acd_xff'                 => $accReq->getXFF(),
					'acd_agent'               => $accReq->getAgent(),
					'acd_filename'            => $accReq->getFileName(),
					'acd_storage_key'         => $accReq->getFileStorageKey(),
					'acd_areas'               => $accReq->getAreas( 'flat' ),
					'acd_registration'        => $dbw->timestamp( $accReq->getRegistration() ),
					'acd_accepted'            => $dbw->timestamp(),
					'acd_user'                => $this->admin->getID(),
					'acd_comment'             => $this->reason,
					'acd_id'                  => $acd_id
				),
				__METHOD__
			);
			if ( is_null( $acd_id ) ) {
				$acd_id = $dbw->insertId(); // set $acd_id to ID inserted
			}
		}

		# Add to global user login system (if there is one)
		if ( !$wgAuth->addUser( $user, $p, $accReq->getEmail(), $accReq->getRealName() ) ) {
			$dbw->rollback();
			# DELETE new rows in case there was a COMMIT somewhere
			$this->acceptRequest_rollback( $dbw, $user->getId(), $acd_id );
			return array( 'accountconf_externaldberror', $context->msg( 'externaldberror' )->escaped() );
		}

		# OK, now remove the request from the queue
		$accReq->remove();

		# Commit this if we make past the CentralAuth system
		# and the groups are added. Next step is sending out an
		# email, which we cannot take back...
		$dbw->commit();

		# Prepare a temporary password email...
		if ( $this->reason != '' ) {
			$msg = "confirmaccount-email-body2-pos{$this->type}";
			# If the user is in a group and there is a welcome for that group, use it
			if ( $group && !wfEmptyMsg( $msg ) ) {
				$ebody = $context->msg( $msg, $user->getName(), $p, $this->reason )->inContentLanguage()->text();
			# Use standard if none found...
			} else {
				$ebody = $context->msg( 'confirmaccount-email-body2',
					$user->getName(), $p, $this->reason )->inContentLanguage()->text();
			}
		} else {
			$msg = "confirmaccount-email-body-pos{$this->type}";
			# If the user is in a group and there is a welcome for that group, use it
			if ( $group && !$context->msg( $msg )->isDisabled() ) {
				$ebody = $context->msg( $msg,
					$user->getName(), $p, $this->reason )->inContentLanguage()->text();
			# Use standard if none found...
			} else {
				$ebody = $context->msg( 'confirmaccount-email-body',
					$user->getName(), $p, $this->reason )->inContentLanguage()->text();
			}
		}

		# Actually send out the email (@TODO: rollback on failure including $wgAuth)
		$result = $user->sendMail(
			$context->msg( 'confirmaccount-email-subj' )->inContentLanguage()->text(),
			$ebody
		);

		# Update user count
		$ssUpdate = new SiteStatsUpdate( 0, 0, 0, 0, 1 );
		$ssUpdate->doUpdate();

		# Safe to hook/log now...
		wfRunHooks( 'AddNewAccount', array( $user, false /* not by email */ ) );
		$user->addNewUserLogEntry();

		# Clear cache for notice of how many account requests there are
		ConfirmAccount::clearAccountRequestCountCache();

		# Delete any attached file and don't stop the whole process if this fails
		if ( $formConfig['CV']['enabled'] ) {
			$key = $accReq->getFileStorageKey();
			if ( $key ) {
				$repoOld = new FSRepo( $wgConfirmAccountFSRepos['accountreqs'] );
				$pathRel = UserAccountRequest::relPathFromKey( $key );
				$oldPath = $repoOld->getZonePath( 'public' ) . '/' . $pathRel;
				if ( file_exists( $oldPath ) ) {
					unlink( $oldPath ); // delete!
				}
			}
		}

		# Start up the user's userpages if set to do so.
		# Will not append, so previous content will be blanked.
		$this->createUserPage( $user );

		# Greet the new user if set to do so.
		$this->createUserTalkPage( $user );

		return array( true, null );
	}

	/*
	 * Rollback an account acceptance *before* the request row and attachment are deleted.
	 * This is mostly here for sanity in case of COMMITs triggered elsewhere.
	 * http://bugs.mysql.com/bug.php?id=30767 behavoir assumed.
	 * @param $dbw Database
	 * @param $user_id int
	 * @param $acd_id int
	 * @return void
	 */
	protected function acceptRequest_rollback( DatabaseBase $dbw, $user_id, $acd_id ) {
		$dbw->begin();
		# DELETE the user in case something caused a COMMIT already somewhere.
		if ( $user_id ) {
			$dbw->delete( 'user', array( 'user_id' => $user_id ), __METHOD__ );
			$dbw->delete( 'user_groups', array( 'ug_user' => $user_id ), __METHOD__ );
		}
		# DELETE the new account_credentials row likewise.
		if ( $acd_id ) {
			$dbw->delete( 'account_credentials', array( 'acd_id' => $acd_id ), __METHOD__ );
		}
		$dbw->commit();
	}

	protected static function getGroupFromType( $type ) {
		global $wgAccountRequestTypes;

		$group = '';
		// Format is (type => (subpage par, group key, group text))
		if ( isset( $wgAccountRequestTypes[$type][1] ) ) {
			$group = $wgAccountRequestTypes[$type][1];
		}

		return $group;
	}

	protected static function getAutoTextFromType( $type ) {
		global $wgAccountRequestTypes;

		$groupText = '';
		// Format is (type => (subpage par, group key, group text))
		if ( isset( $wgAccountRequestTypes[$type][2] ) ) {
			$groupText = $wgAccountRequestTypes[$type][2];
		}

		return $groupText;
	}

	protected function createUserPage( User $user ) {
		global $wgMakeUserPageFromBio, $wgAutoUserBioText;
		global $wgConfirmAccountSortkey, $wgContLang;

		$body = ''; // page text

		if ( $wgMakeUserPageFromBio ) {
			# Add account request bio to userpage
			$body .= $this->bio;
			# Add any automatic text for all confirmed accounts
			if ( $wgAutoUserBioText != '' ) {
				$body .= "\n\n{$wgAutoUserBioText}";
			}
		}

		# Add any automatic text for confirmed accounts of this type
		$autoText = self::getAutoTextFromType( $this->type );
		if ( $autoText != '' ) {
			$body .= "\n\n{$autoText}";
		}

		# Add any areas of interest categories...
		foreach ( ConfirmAccount::getUserAreaConfig() as $name => $conf ) {
			if ( in_array( $name, $this->areas ) ) {
				# General userpage text for anyone with this interest
				if ( $conf['userText'] != '' ) {
					$body .= $conf['userText'];
				}
				# Message for users with this interested with the given account type
				if ( isset( $conf['grpUserText'][$this->type] )
					&& $conf['grpUserText'][$this->type] != '' )
				{
					$body .= $conf['grpUserText'];
				}
			}
		}

		# Set sortkey and use it on userpage. This can be used to
		# normalize things like firstname, lastname and so fourth.
		if ( !empty( $wgConfirmAccountSortkey ) ) {
			$sortKey = preg_replace(
				$wgConfirmAccountSortkey[0],
				$wgConfirmAccountSortkey[1],
				$user->getUserPage()->getText()
			);
			$body .= "\n{{DEFAULTSORT:{$sortKey}}}";
			# Clean up any other categories...
			$catNS = $wgContLang->getNSText( NS_CATEGORY );
			$replace = '/\[\[' . preg_quote( $catNS ) . ':([^\]]+)\]\]/i'; // [[Category:x]]
			$with = "[[{$catNS}:$1|" . str_replace( '$', '\$', $sortKey ) . "]]"; // [[Category:x|sortkey]]
			$body = preg_replace( $replace, $with, $body );
		}

		# Create userpage!
		$article = new WikiPage( $user->getUserPage() );
		$article->doEdit(
			$body,
			wfMessage( 'confirmaccount-summary' )->inContentLanguage()->text(),
			EDIT_MINOR
		);
	}

	protected function createUserTalkPage( User $user ) {
		global $wgAutoWelcomeNewUsers;

		if ( $wgAutoWelcomeNewUsers ) {
			if ($this->reason == '') {
				$msg = "confirmaccount-welc-pos{$this->type}";
				$welcome = wfEmptyMsg( $msg )
					? wfMessage( 'confirmaccount-welc' )->text()
					: wfMessage( $msg )->text(); // custom message
			} else {
				$welcome = $this->reason;
			}
			# Add user welcome message!
			$article = new WikiPage( $user->getTalkPage() );
			$article->doEdit(
				"{$welcome} ~~~~",
				wfMessage( 'confirmaccount-wsum' )->inContentLanguage()->text(),
				EDIT_MINOR,
				false,
				$this->admin
			);
		}
	}
}
