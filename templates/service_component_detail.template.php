<?php
/* @var $comp  The service component object */
?>
<h3>
	<span class="pull-right">
		<small>	
			<a href="<?php echo build_url(['call' => null, 'view' => '_edit_service_component', 'service_componentid' => $comp->id]); ?>"><i class="icon-wrench"></i>Edit</a>
			<a href="<?php echo build_url(['call' => null, 'call' => 'service_comp_slides']); ?>"><i class="icon-film"></i>Slides</a>
		</small>
	</span>
	<?php $comp->printFieldValue('title'); ?>
</h3>
<div id="comp-detail">
	<?php
	$comp->printSummary();
?>
</div>
<script>
applyNarrowColumns('#comp-detail');
</script>




