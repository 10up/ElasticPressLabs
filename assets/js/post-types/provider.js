import { createContext, useContext } from '@wordpress/element';

export const PostTypeContext = createContext();

export const usePostTypeSettings = () => useContext(PostTypeContext);
