<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2014, Phoronix Media
	Copyright (C) 2014, Michael Larabel

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program. If not, see <http://www.gnu.org/licenses/>.
*/


class phoromatic_users implements pts_webui_interface
{
	public static function page_title()
	{
		return 'Account Administrator';
	}
	public static function page_header()
	{
		return null;
	}
	public static function preload($PAGE)
	{
		return true;
	}
	public static function render_page_process($PATH)
	{
		if($_SESSION['AdminLevel'] > 3)
		{
			echo phoromatic_error_page('Unauthorized Access', 'You aren\'t an account administrator!');
			return;
		}

		if(isset($_POST['username']) && isset($_POST['password']) && isset($_POST['confirm_password']) && isset($_POST['email']))
		{
			// REGISTER NEW USER
			if(strlen($_POST['username']) < 4 || strpos($_POST['username'], ' ') !== false)
			{
				phoromatic_error_page('Oops!', 'Please go back and ensure the supplied username is at least four characters long and contains no spaces.');
				return false;
			}
			if(in_array(strtolower($_POST['username']), array('admin', 'administrator')))
			{
				phoromatic_error_page('Oops!', $_POST['username'] . ' is a reserved and common username that may be used for other purposes, please make a different selection.');
				return false;
			}
			if(strlen($_POST['password']) < 6)
			{
				phoromatic_error_page('Oops!', 'Please go back and ensure the supplied password is at least six characters long.');
				return false;
			}
			if($_POST['password'] != $_POST['confirm_password'])
			{
				phoromatic_error_page('Oops!', 'Please go back and ensure the supplied password matches the password confirmation.');
				return false;
			}
			if($_POST['email'] == null || filter_var($_POST['email'], FILTER_VALIDATE_EMAIL) == false)
			{
				phoromatic_error_page('Oops!', 'Please enter a valid email address.');
				return false;
			}

			$valid_user_name_chars = '1234567890-_.abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
			for($i = 0; $i < count($_POST['username']); $i++)
			{
				if(strpos($valid_user_name_chars, substr($_POST['username'], $i, 1)) === false)
				{
					phoromatic_error_page('Oops!', 'Please go back and ensure a valid user-name. The character <em>' . substr($_POST['username'], $i, 1) . '</em> is not allowed.');
					return false;
				}
			}

			$matching_users = phoromatic_server::$db->querySingle('SELECT UserName FROM phoromatic_users WHERE UserName = \'' . SQLite3::escapeString($_POST['username']) . '\'');
			if(!empty($matching_users))
			{
				phoromatic_error_page('Oops!', 'The user-name is already taken.');
				return false;
			}

			if(!isset($_POST['admin_level']) || $_POST['admin_level'] == 1 || !is_numeric($_POST['admin_level']))
			{
				phoromatic_error_page('Oops!', 'Invalid administration level.');
				return false;
			}

			$stmt = phoromatic_server::$db->prepare('SELECT Salt FROM phoromatic_accounts WHERE AccountID = :account_id');
			$stmt->bindValue(':account_id', $_SESSION['AccountID']);
			$result = $stmt->execute();
			$row = $result->fetchArray();
			$account_salt = $row['Salt'];
			$user_id = pts_strings::random_characters(4, true);
			$salted_password = hash('sha256', $account_salt . $_POST['password']);

			pts_logger::add_to_log($_SERVER['REMOTE_ADDR'] . ' created a new account: ' . $user_id . ' - ' . $_SESSION['AccountID']);

			$stmt = phoromatic_server::$db->prepare('INSERT INTO phoromatic_users (UserID, AccountID, UserName, Email, Password, CreatedOn, LastIP, AdminLevel) VALUES (:user_id, :account_id, :user_name, :email, :password, :current_time, :last_ip, :admin_level)');
			$stmt->bindValue(':user_id', $user_id);
			$stmt->bindValue(':account_id', $_SESSION['AccountID']);
			$stmt->bindValue(':user_name', $_POST['username']);
			$stmt->bindValue(':email', $_POST['email']);
			$stmt->bindValue(':password', $salted_password);
			$stmt->bindValue(':last_ip', $_SERVER['REMOTE_ADDR']);
			$stmt->bindValue(':current_time', phoromatic_server::current_time());
			$stmt->bindValue(':admin_level', $_POST['admin_level']);
			$result = $stmt->execute();

			$stmt = phoromatic_server::$db->prepare('INSERT INTO phoromatic_user_settings (UserID, AccountID) VALUES (:user_id, :account_id)');
			$stmt->bindValue(':user_id', $user_id);
			$stmt->bindValue(':account_id', $_SESSION['AccountID']);
			$result = $stmt->execute();

			phoromatic_add_activity_stream_event('users', $_POST['username'], 'added');
		}

		$main = '<h2>Users</h2>
			<p>Users associated with this account. Phoromatic users can be one of several tiers with varying privileges:</p>
			<ol>
				<li><strong>Main Administrator:</strong> The user with full control over the account, the one who originally signed up for the Phoromatic account.</li>
				<li><strong>Administrator:</strong> Additional users created by the main administrator with the same access rights as the main administrator.</li>
				<li><strong>Viewer:</strong> Additional users created by the main administrator that have access to view data but not to create new schedules, alter system settings, etc.</li>
			</ol>
			<div class="pts_phoromatic_info_box_area">

				<div style="margin: 0 20%;">
					<ul>
						<li><h1>All Users</h1></li>';

					$stmt = phoromatic_server::$db->prepare('SELECT * FROM phoromatic_users WHERE AccountID = :account_id ORDER BY UserName ASC');
					$stmt->bindValue(':account_id', $_SESSION['AccountID']);
					$result = $stmt->execute();
					$row = $result->fetchArray();

					do
					{
						switch($row['AdminLevel'])
						{
							case 0:
								$level = 'Disabled';
								break;
							case 1:
								$level = 'Main Administrator';
								break;
							case 2:
								$level = 'Administrator';
								break;
							case 3:
								$level = 'Power User';
								break;
							case 10:
								$level = 'Viewer';
								break;
						}

						$main .= '<a href="#"><li>' . $row['UserName'] . '<br /><em><strong>' . $level . '</strong> - Last Login: ' . (empty($row['LastLogin']) ? 'Never' : date('j F Y H:i', strtotime($row['LastLogin']))) . '</em></li></a>';
					}
					while($row = $result->fetchArray());


			$main .= '</ul>
				</div>
			</div>';

		$main .= '<hr /><form action="' . $_SERVER['REQUEST_URI'] . '" name="add_user" id="add_user" method="post" onsubmit="return validate_new_user();"><h2>Create Additional Account</h2>
			<p>Administrators can create extra accounts to be associated with this account\'s systems, schedules, and test data.</p>
			<h3>User</h3>
			<p><input type="text" name="username" /></p>
			<h3>Password</h3>
			<p><input type="password" name="password" /></p>
			<h3>Confirm Password</h3>
			<p><input type="password" name="confirm_password" /></p>
			<h3>Email</h3>
			<p><input type="text" name="email" /></p>
			<h3>Administration Level</h3>
			<p><select name="admin_level">';

		if($_SESSION['AdminLevel'] == 1)
			$main .= '<option value="2">Administrator</option>';

		if($_SESSION['AdminLevel'] <= 2)
			$main .= '<option value="3">Power User</option>';
		if($_SESSION['AdminLevel'] <= 3)
			$main .= '<option value="10">Viewer</option>';

		$main .= '
			</select></p>
			<p><input name="submit" value="Add User" type="submit" /></p>
			</form>';

		echo phoromatic_webui_header_logged_in();
		echo phoromatic_webui_main($main, phoromatic_webui_right_panel_logged_in());
		echo phoromatic_webui_footer();
	}
}

?>
