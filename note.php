<?php
class Note extends Plugin {

	function about() {
		return array(null,
			"Adds support for setting article notes",
			"fox");
	}

	function init($host) {
		$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/note.js");
	}

	function get_css() {
		return file_get_contents(__DIR__ . "/note.css");
	}

	function hook_article_button($line) {
		return "<i class='material-icons' onclick=\"Plugins.Note.edit(".$line["id"].")\"
			style='cursor : pointer' title=\"".__('Edit article note')."\">note</i>";
	}

	function edit() : void {
		$id = clean($_REQUEST['id']);

		$sth = $this->pdo->prepare("SELECT note FROM ttrss_user_entries WHERE
			ref_id = ? AND owner_uid = ?");
		$sth->execute([$id, $_SESSION['uid']]);

		if ($row = $sth->fetch()) {

			$note = $row['note'];

			print \Controls\hidden_tag("id", $id);
			print \Controls\pluginhandler_tags($this, "setnote");

			?>
			<textarea dojoType='dijit.form.SimpleTextarea'
				style='font-size : 12px; width : 98%; height: 100px;'
				name='note'><?= $note ?></textarea>
			<?php
		}
		?>
		<footer class='text-center'>
			<?= \Controls\submit_tag(__('Save')) ?>
			<?= \Controls\cancel_dialog_tag(__('Cancel')) ?>
		</footer>
		<?php
	}

	function setNote() : void {
		$id = (int)clean($_REQUEST["id"]);
		$note = $_REQUEST["note"]; // TODO: support MD, render by js

		$sth = $this->pdo->prepare("UPDATE ttrss_user_entries SET note = ?
			WHERE ref_id = ? AND owner_uid = ?");
		$sth->execute([$note, $id, $_SESSION['uid']]);

		print json_encode(["id" => $id, "note" => $note]);
	}

	function api_version() {
		return 2;
	}

}
