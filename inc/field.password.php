<?
	//==========================================================================================
	class PasswordField extends SingleLineTextInputField
	//==========================================================================================
	{
		//--------------------------------------------------------------------------------------		
		protected function /*string*/ render_internal(&$output_buf) {
		// render_settings: form_method, name_attr, id_attr
		//--------------------------------------------------------------------------------------
			$output_buf .= sprintf(
				"<input %s %s type='password' class='form-control' id='%s' name='%s' %s value='' %s />",
				$this->get_disabled_attr(),
				$this->get_required_attr(),
				$this->get_control_id(),
				$this->get_control_name(),
				$this->get_maxlen_attr(),
				$this->get_focus_attr()
			);
			return $output_buf;
		}
	}
?>