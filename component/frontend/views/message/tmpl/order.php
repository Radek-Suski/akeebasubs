<?php defined('KOOWA') or die(); ?>

<!--  --
<style src="media://com_akeebasubs/css/frontend.css?<?=AKEEBASUBS_VERSIONHASH?>" />
<!--  -->

<?=KFactory::get('site::com.akeebasubs.model.configs')->getConfig()->stepsbar ? @template('steps',array('step' => 'done')) : ''?>

<h1 class="componentheading">
	<?= @escape(@text('COM_AKEEBASUBS_MESSAGE_THANKYOU')) ?>
</h1>

<?=$message->ordertext?>

<div class="akeebasubs-goback">
	<p><a href="<?=JURI::base()?>"><?=@text('back')?></a></p>
</div>